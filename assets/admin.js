(function ($) {
    'use strict';

    var $progress = $('#taisa-imgopt-progress');

    function setProgress(message, percent) {
        percent = Math.max(0, Math.min(100, percent || 0));
        $progress
            .addClass('is-active')
            .html(
                '<div>' + escapeHtml(message) + '</div>' +
                '<div class="taisa-imgopt-progress-bar"><span style="width:' + percent + '%"></span></div>'
            );
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function ajax(action, data) {
        return $.post(
            ajaxurl,
            $.extend(
                {
                    action: action,
                    nonce: TaisaImgOpt.nonce
                },
                data || {}
            )
        );
    }

    function getErrorMessage(response) {
        if (response && response.responseJSON && response.responseJSON.data && response.responseJSON.data.message) {
            return response.responseJSON.data.message;
        }
        if (response && response.data && response.data.message) {
            return response.data.message;
        }
        return TaisaImgOpt.strings.error;
    }

    function withDisabledButtons(callback) {
        var $buttons = $('[data-taisa-action]');
        $buttons.prop('disabled', true);
        return callback().always(function () {
            $buttons.prop('disabled', false);
        });
    }

    function runOffsetBatches(options) {
        var deferred = $.Deferred();
        var offset = 0;
        var total = 0;
        var limit = options.limit || 20;
        var stopped = false;

        (options.prepareAction ? ajax(options.prepareAction) : $.Deferred().resolve({ success: true, data: { total: 1, limit: 5 } }).promise())
            .done(function (response) {
                if (!response.success) {
                    deferred.reject(response);
                    return;
                }

                total = parseInt(response.data.total, 10) || 0;
                limit = parseInt(response.data.limit, 10) || limit;
                if (!total) {
                    setProgress(options.emptyMessage || TaisaImgOpt.strings.done, 100);
                    deferred.resolve(response);
                    return;
                }

                tick();
            })
            .fail(function (response) {
                deferred.reject(response);
            });

        function tick() {
            if (stopped) { return; }
            ajax(options.batchAction, {
                offset: offset,
                limit: limit
            })
                .done(function (response) {
                    if (!response.success) {
                        deferred.reject(response);
                        return;
                    }

                    var processed = parseInt(response.data.processed, 10) || 0;
                    offset += processed;
                    setProgress(response.data.message || TaisaImgOpt.strings.working, total ? (offset / total) * 100 : 100);

                    if (response.data.done || processed === 0) {
                        setProgress(options.doneMessage || response.data.message || TaisaImgOpt.strings.done, 100);
                        deferred.resolve(response);
                        return;
                    }

                    if (options.singleStep) { options.onBatch(response.data); return; }
                    setTimeout(tick, options.delay || 0);
                })
                .fail(function (response) {
                    deferred.reject(response);
                });
        }

        var promise = deferred.promise();
        promise.pause = function () { stopped = true; };
        promise.resume = function () { stopped = false; tick(); };
        promise.isPaused = function () { return stopped; };
        return promise;
    }

    var cleanupRun = null;
    function cleanupProgress(data) {
        var done = parseInt(data.cursor, 10) || 0;
        var total = parseInt(data.total, 10) || 0;
        setProgress('Objetivo: ' + (data.target || (data.disabled || []).join(', ')) + '. Adjuntos procesados: ' + done + '/' + total + '; borrados: ' + (data.deleted || 0) + '; referencias compartidas conservadas: ' + (data.retained || 0) + '; errores: ' + (data.errors || 0) + '. Estado: ' + (data.status || 'en curso') + (data.in_flight ? ' (petición en curso)' : '') + '.', total ? (done / total) * 100 : 0);
    }
    function startCleanup(resume) {
        var d = $.Deferred();
        var start = function () {
            cleanupRun = runOffsetBatches({
                prepareAction: resume ? null : 'taisa_imgopt_prepare_cleanup',
                batchAction: 'taisa_imgopt_cleanup_batch', limit: 5, singleStep: true, delay: 3000,
                onBatch: function (batch) {
                    cleanupProgress(batch);
                    if (batch.status === 'paused' || batch.status === 'cancelled' || batch.done) { cleanupRun = null; d.resolve(batch); return; }
                    setTimeout(function () { if (cleanupRun && !cleanupRun.isPaused()) cleanupRun.resume(); }, 3000);
                }
            });
            cleanupRun.done(d.resolve).fail(d.reject);
        };
        if (resume) { start(); }
        else { start(); }
        return d.promise();
    }

    function loadCleanupStatus() {
        ajax('taisa_imgopt_cleanup_status').done(function (r) {
            if (r.success && r.data.status !== 'none') {
                var current = r.data;
                cleanupProgress(current);
                if (current.status === 'running') {
                    if (current.in_flight) {
                        setProgress('Hay una petición de limpieza en curso. Esperando su respuesta antes de enviar otro lote…', 0);
                        setTimeout(loadCleanupStatus, 2000);
                    } else { startCleanup(true).fail(function (error) { setProgress(getErrorMessage(error), 0); }); }
                }
            }
        });
    }
    loadCleanupStatus();

    $(document).on('click', '[data-taisa-cleanup-control]', function () {
        var command = $(this).data('taisa-cleanup-control');
        if (command === 'cancel' && !window.confirm('¿Cancelar y abandonar esta limpieza? Los archivos ya borrados no se restauran.')) { return; }
        if (command === 'pause' && cleanupRun && cleanupRun.pause) { cleanupRun.pause(); }
        ajax('taisa_imgopt_cleanup_control', { command: command }).done(function (r) {
            if (!r.success) { setProgress(getErrorMessage(r), 0); return; }
            if (command === 'resume') { startCleanup(true).fail(function (error) { setProgress(getErrorMessage(error), 0); }); }
            else { setProgress(command === 'pause' ? 'Pausa solicitada. La petición en curso terminará; no se enviarán nuevos lotes.' : 'Cancelación solicitada. La petición en curso terminará antes de abandonar el trabajo.', 0); }
            if (command !== 'resume') { loadCleanupStatus(); }
        }).fail(function (r) { setProgress(getErrorMessage(r), 0); });
    });

    function runUsageScan() {
        var deferred = $.Deferred();
        var dbOffset = 0;
        var dbTotal = 0;
        var dbLimit = 10;
        var urlOffset = 0;
        var urlTotal = 0;
        var urlLimit = 5;

        ajax('taisa_imgopt_prepare_usage_scan')
            .done(function (response) {
                if (!response.success) {
                    deferred.reject(response);
                    return;
                }

                dbTotal = parseInt(response.data.total, 10) || 0;
                dbLimit = parseInt(response.data.limit, 10) || dbLimit;
                urlTotal = parseInt(response.data.url_total, 10) || 0;
                urlLimit = parseInt(response.data.url_limit, 10) || urlLimit;
                scanDatabase();
            })
            .fail(function (response) {
                deferred.reject(response);
            });

        function scanDatabase() {
            if (!dbTotal) {
                scanFrontend();
                return;
            }

            ajax('taisa_imgopt_usage_scan_batch', {
                offset: dbOffset,
                limit: dbLimit
            })
                .done(function (response) {
                    if (!response.success) {
                        deferred.reject(response);
                        return;
                    }

                    var processed = parseInt(response.data.processed, 10) || 0;
                    dbOffset += processed;
                    setProgress(response.data.message || TaisaImgOpt.strings.working, dbTotal ? (dbOffset / dbTotal) * 70 : 70);

                    if (response.data.done || processed === 0) {
                        scanFrontend();
                        return;
                    }

                    scanDatabase();
                })
                .fail(function (response) {
                    deferred.reject(response);
                });
        }

        function scanFrontend() {
            if (!urlTotal) {
                finish();
                return;
            }

            ajax('taisa_imgopt_frontend_scan_batch', {
                offset: urlOffset,
                limit: urlLimit
            })
                .done(function (response) {
                    if (!response.success) {
                        deferred.reject(response);
                        return;
                    }

                    var processed = parseInt(response.data.processed, 10) || 0;
                    urlOffset += processed;
                    setProgress(response.data.message || TaisaImgOpt.strings.working, 70 + (urlTotal ? (urlOffset / urlTotal) * 30 : 30));

                    if (response.data.done || processed === 0) {
                        finish();
                        return;
                    }

                    scanFrontend();
                })
                .fail(function (response) {
                    deferred.reject(response);
                });
        }

        function finish() {
            ajax('taisa_imgopt_finish_usage_scan')
                .done(function (response) {
                    if (!response.success) {
                        deferred.reject(response);
                        return;
                    }
                    setProgress(response.data.message || TaisaImgOpt.strings.done, 100);
                    deferred.resolve(response);
                })
                .fail(function (response) {
                    deferred.reject(response);
                });
        }

        return deferred.promise();
    }

    function runResizeLarge() {
        var deferred = $.Deferred();
        var total = 0;
        var limit = 5;

        ajax('taisa_imgopt_prepare_resize')
            .done(function (response) {
                if (!response.success) {
                    deferred.reject(response);
                    return;
                }

                total = parseInt(response.data.total, 10) || 0;
                limit = parseInt(response.data.limit, 10) || limit;
                tick();
            })
            .fail(function (response) {
                deferred.reject(response);
            });

        function tick() {
            ajax('taisa_imgopt_resize_batch', {
                limit: limit
            })
                .done(function (response) {
                    if (!response.success) {
                        deferred.reject(response);
                        return;
                    }

                    var remaining = parseInt(response.data.remaining, 10) || 0;
                    var processed = parseInt(response.data.processed, 10) || 0;
                    var doneCount = Math.max(0, total - remaining);
                    setProgress(response.data.message || TaisaImgOpt.strings.working, total ? (doneCount / total) * 100 : 100);

                    if (response.data.done || processed === 0) {
                        deferred.resolve(response);
                        return;
                    }

                    tick();
                })
                .fail(function (response) {
                    deferred.reject(response);
                });
        }

        return deferred.promise();
    }

    $(document).on('click', '[data-taisa-action]', function () {
        var action = $(this).data('taisa-action');
        var runner;

        if (action === 'size-scan') {
            runner = function () {
                setProgress(TaisaImgOpt.strings.working, 0);
                return runOffsetBatches({
                    prepareAction: 'taisa_imgopt_prepare_size_scan',
                    batchAction: 'taisa_imgopt_size_scan_batch',
                    doneMessage: TaisaImgOpt.strings.done
                });
            };
        }

        if (action === 'usage-scan') {
            runner = function () {
                setProgress(TaisaImgOpt.strings.working, 0);
                return runUsageScan();
            };
        }

        if (action === 'large-scan') {
            runner = function () {
                setProgress(TaisaImgOpt.strings.working, 0);
                return runOffsetBatches({
                    prepareAction: 'taisa_imgopt_prepare_large_scan',
                    batchAction: 'taisa_imgopt_large_scan_batch',
                    doneMessage: TaisaImgOpt.strings.done
                });
            };
        }

        if (action === 'cleanup-disabled') {
            if (!$('#taisa-confirm-cleanup').is(':checked')) {
                setProgress(TaisaImgOpt.strings.confirmClean, 0);
                return;
            }

            runner = function () {
                setProgress(TaisaImgOpt.strings.working, 0);
                return startCleanup(false);
            };
        }

        if (action === 'resize-large') {
            if (!$('#taisa-confirm-resize').is(':checked')) {
                setProgress(TaisaImgOpt.strings.confirmResize, 0);
                return;
            }

            runner = function () {
                setProgress(TaisaImgOpt.strings.working, 0);
                return runResizeLarge();
            };
        }

        if (!runner) {
            return;
        }

        withDisabledButtons(runner)
            .done(function () {
                setTimeout(function () {
                    window.location.reload();
                }, 800);
            })
            .fail(function (response) {
                setProgress(getErrorMessage(response), 100);
            });
    });
})(jQuery);
