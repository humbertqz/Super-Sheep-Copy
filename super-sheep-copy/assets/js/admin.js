/* global sscData, jQuery */
(function ($) {
  "use strict";

  // ── Estado del job en curso ────────────────────────────────────────────
  var currentJobId = null;
  var pollInterval = null;
  var pollDelay = 2000; // ms entre polls de estado
  var pollMaxMs = 900000; // 15 min — tras este tiempo de polling sin respuesta final, mostrar aviso
  var simulateInterval = null; // temporizador de progreso simulado
  var simulatedPercent = 0;

  // Fases simuladas: [ porcentaje_objetivo, etiqueta, duración_ms ]
  // El total suma ~90 s para un sitio mediano; se detiene en 90% hasta recibir respuesta.
  var BACKUP_PHASES = [
    [8, sscData.strings.phaseInit || "Iniciando…", 2000],
    [20, sscData.strings.phaseDB || "Volcando base de datos…", 10000],
    [55, sscData.strings.phaseFiles || "Empaquetando archivos…", 40000],
    [75, sscData.strings.phaseManifest || "Generando manifest…", 5000],
    [88, sscData.strings.phaseChecksum || "Calculando checksum…", 4000],
    [90, sscData.strings.phaseWait || "Finalizando…", 99999], // espera hasta respuesta
  ];

  var RESTORE_PHASES = [
    [5, sscData.strings.phaseValidate || "Validando respaldo…", 2000],
    [
      12,
      sscData.strings.phaseSnapshot || "Creando respaldo de seguridad…",
      12000,
    ],
    [28, sscData.strings.phaseExtract || "Extrayendo archivos…", 20000],
    [60, sscData.strings.phaseImportDB || "Importando base de datos…", 20000],
    [80, sscData.strings.phaseRewrite || "Reescribiendo URLs…", 8000],
    [88, sscData.strings.phaseCache || "Limpiando cache…", 3000],
    [90, sscData.strings.phaseWait || "Finalizando…", 99999],
  ];

  // ── Crear respaldo ────────────────────────────────────────────────────
  $("#ssc-create-backup").on("click", function () {
    $(this).prop("disabled", true);
    startSimulatedProgress(
      BACKUP_PHASES,
      "#ssc-progress-wrap",
      "#ssc-progress-bar",
      "#ssc-progress-label",
    );

    $.post(sscData.ajaxUrl, {
      action: "ssc_create_backup",
      nonce: sscData.nonce,
      label: "",
    })
      .done(function (response) {
        if (response.success && response.data && response.data.job_id) {
          // Job aceptado — el servidor ya cerró la conexión HTTP y ejecuta
          // el respaldo en segundo plano. Hacemos polling hasta completar.
          startPolling(
            response.data.job_id,
            "ssc_get_backup_status",
            function () {
              finishProgress(
                "#ssc-progress-bar",
                "#ssc-progress-label",
                sscData.strings.done || "Respaldo completado.",
              );
              setTimeout(function () {
                hideProgress("#ssc-progress-wrap");
                window.location.reload();
              }, 1200);
            },
            function (msg, lostContact) {
              stopSimulation();
              hideProgress("#ssc-progress-wrap");
              if (lostContact) {
                showWarning(msg);
                // No re-habilitar el botón: el proceso puede seguir en segundo plano.
              } else {
                showError(msg);
                $("#ssc-create-backup").prop("disabled", false);
              }
            },
          );
        } else if (sscData.runningBackupJobId) {
          // Servidor rechazó porque ya hay un respaldo en curso.
          // En vez de mostrar error, adjuntarse al job existente.
          $("#ssc-progress-label").text(
            sscData.strings.backupInProgress || "Respaldo en curso…",
          );
          startPolling(
            sscData.runningBackupJobId,
            "ssc_get_backup_status",
            function () {
              finishProgress(
                "#ssc-progress-bar",
                "#ssc-progress-label",
                sscData.strings.done || "Respaldo completado.",
              );
              setTimeout(function () {
                hideProgress("#ssc-progress-wrap");
                window.location.reload();
              }, 1200);
            },
            function (msg, lostContact) {
              stopSimulation();
              hideProgress("#ssc-progress-wrap");
              if (lostContact) {
                showWarning(msg);
              } else {
                showError(msg);
                $("#ssc-create-backup").prop("disabled", false);
              }
            },
          );
        } else if (
          response &&
          response.success === false &&
          response.data &&
          response.data.message
        ) {
          // Explicit server-side error (e.g. nonce failure, permission denied).
          stopSimulation();
          hideProgress("#ssc-progress-wrap");
          showError(response.data.message);
          $("#ssc-create-backup").prop("disabled", false);
        } else {
          // Ambiguous response: fastcgi_finish_request() may have closed the
          // connection before the full JSON reached jQuery, causing a parse
          // error or empty body. The backup IS likely running in background.
          // Reload so the page detects runningBackupJobId and resumes polling.
          stopSimulation();
          hideProgress("#ssc-progress-wrap");
          showWarning(
            sscData.strings.errorPollTimeout || sscData.strings.error,
          );
          setTimeout(function () {
            window.location.reload();
          }, 4000);
        }
      })
      .fail(function () {
        // ANY network/HTTP failure on the create-backup initiation is treated as
        // ambiguous: fastcgi_finish_request() closes the socket early, which can
        // cause the Apache proxy to return 500/502/504/0/408 even when the backup
        // PHP process is running fine in background. Always reload so the page can
        // pick up runningBackupJobId and resume polling.
        stopSimulation();
        hideProgress("#ssc-progress-wrap");
        showWarning(sscData.strings.errorPollTimeout || sscData.strings.error);
        setTimeout(function () {
          window.location.reload();
        }, 4000);
      });
  });

  // ── Eliminar respaldo ─────────────────────────────────────────────────
  $(document).on("click", ".ssc-delete-btn", function (e) {
    e.preventDefault();
    var filename = $(this).data("filename");

    if (!confirm(sscData.strings.confirmDelete)) {
      return;
    }

    // Support both card (.ssc-backup-card) and legacy table row (tr).
    var $card = $(this).closest(".ssc-backup-card, tr");
    $card.addClass("is-deleting");

    $.post(sscData.ajaxUrl, {
      action: "ssc_delete_backup",
      nonce: sscData.nonce,
      filename: filename,
    })
      .done(function (response) {
        if (response.success) {
          $card.fadeOut(250, function () {
            $(this).remove();
            updateListStats();
          });
        } else {
          $card.removeClass("is-deleting");
          showError(response.data.message || sscData.strings.error);
        }
      })
      .fail(function () {
        $card.removeClass("is-deleting");
        showError(sscData.strings.error);
      });
  });

  // ── Ver manifest ──────────────────────────────────────────────────────
  $(document).on("click", ".ssc-manifest-btn", function (e) {
    e.preventDefault();
    var $btn = $(this);
    var filename = $btn.data("filename");

    $btn.prop("disabled", true);

    $.post(sscData.ajaxUrl, {
      action: "ssc_get_manifest",
      nonce: sscData.nonce,
      filename: filename,
    })
      .done(function (response) {
        $btn.prop("disabled", false);
        if (response.success) {
          openManifestModal(JSON.stringify(response.data, null, 2));
        } else {
          showError(
            (response.data && response.data.message) || sscData.strings.error,
          );
        }
      })
      .fail(function () {
        $btn.prop("disabled", false);
        showError(sscData.strings.error);
      });
  });

  // ── Modal de manifest ─────────────────────────────────────────────────
  function openManifestModal(json) {
    var $modal = $("#ssc-manifest-modal");
    if (!$modal.length) {
      // Fallback para páginas sin modal (log, restore, etc.)
      alert(json);
      return;
    }
    $("#ssc-manifest-content").text(json);
    $modal.removeAttr("hidden");
    $("#ssc-modal-close, #ssc-modal-backdrop").one("click", closeManifestModal);
    $(document).one("keydown.sscModal", function (ev) {
      if (ev.key === "Escape") {
        closeManifestModal();
      }
    });
  }

  function closeManifestModal() {
    var $modal = $("#ssc-manifest-modal");
    $modal.attr("hidden", "");
    $(document).off("keydown.sscModal");
  }

  // ── Select all / bulk delete ──────────────────────────────────────────
  $(document).on("change", "#ssc-select-all", function () {
    var checked = $(this).prop("checked");
    $(".ssc-item-check").prop("checked", checked);
    $(".ssc-backup-card").toggleClass("is-selected", checked);
    updateBulkToolbar();
  });

  $(document).on("change", ".ssc-item-check", function () {
    $(this)
      .closest(".ssc-backup-card")
      .toggleClass("is-selected", $(this).prop("checked"));
    var total = $(".ssc-item-check").length;
    var checked = $(".ssc-item-check:checked").length;
    $("#ssc-select-all").prop("indeterminate", checked > 0 && checked < total);
    $("#ssc-select-all").prop("checked", checked === total && total > 0);
    updateBulkToolbar();
  });

  function updateBulkToolbar() {
    var count = $(".ssc-item-check:checked").length;
    if (count > 0) {
      $("#ssc-bulk-delete").show();
    } else {
      $("#ssc-bulk-delete").hide();
    }
  }

  $(document).on("click", "#ssc-bulk-delete", function () {
    var $checked = $(".ssc-item-check:checked");
    if (!$checked.length) {
      return;
    }

    var names = $checked
      .map(function () {
        return $(this).val();
      })
      .get();
    var label = names.length === 1 ? "1 respaldo" : names.length + " respaldos";

    if (!confirm(sscData.strings.confirmDelete)) {
      return;
    }

    // Delete sequentially.
    var queue = names.slice();

    function deleteNext() {
      if (!queue.length) {
        updateListStats();
        $("#ssc-select-all")
          .prop("checked", false)
          .prop("indeterminate", false);
        updateBulkToolbar();
        return;
      }
      var fn = queue.shift();
      var $card = $('.ssc-backup-card[data-filename="' + CSS.escape(fn) + '"]');
      $card.addClass("is-deleting");

      $.post(sscData.ajaxUrl, {
        action: "ssc_delete_backup",
        nonce: sscData.nonce,
        filename: fn,
      })
        .done(function (response) {
          if (response.success) {
            $card.fadeOut(200, function () {
              $(this).remove();
              deleteNext();
            });
          } else {
            $card.removeClass("is-deleting");
            showError(
              (response.data && response.data.message) || sscData.strings.error,
            );
            deleteNext(); // Continue with rest even if one fails.
          }
        })
        .fail(function () {
          $card.removeClass("is-deleting");
          showError(sscData.strings.error);
          deleteNext();
        });
    }

    deleteNext();
  });

  // ── Empty state create button alias ──────────────────────────────────
  $(document).on("click", "#ssc-create-backup-empty", function () {
    $("#ssc-create-backup").trigger("click");
  });

  // ── Update stat: backup count after deletions ─────────────────────────
  function updateListStats() {
    var remaining = $(".ssc-backup-card").length;
    var $val = $(".ssc-stat-card:first-child .ssc-stat-value");
    if ($val.length) {
      $val.text(remaining);
    }
  }

  // ── Restaurar (desde listado) ─────────────────────────────────────────
  $(document).on("click", ".ssc-restore-btn", function (e) {
    e.preventDefault();
    var filename = $(this).data("filename");

    if (!confirm(sscData.strings.confirmRestore)) {
      return;
    }

    startSimulatedProgress(
      RESTORE_PHASES,
      "#ssc-progress-wrap",
      "#ssc-progress-bar",
      "#ssc-progress-label",
    );

    // Pausar el heartbeat de WordPress durante la restauración para evitar que
    // wp-auth-check detecte la sesión inválida (resultado del reemplazo de la DB)
    // y muestre un modal de login antes del modal de "restauración completada".
    if (window.wp && window.wp.heartbeat) {
      window.wp.heartbeat.interval("slow");
    }

    $.post(sscData.ajaxUrl, {
      action: "ssc_restore_backup",
      nonce: sscData.nonce,
      filename: filename,
    })
      .done(function (response) {
        if (
          response &&
          response.success &&
          response.data &&
          response.data.job_id
        ) {
          // Job aceptado — el servidor ya cerró la conexión HTTP y ejecuta
          // la restauración en segundo plano. Hacemos polling hasta completar.
          startPolling(
            response.data.job_id,
            "ssc_get_restore_status",
            function () {
              finishProgress(
                "#ssc-progress-bar",
                "#ssc-progress-label",
                sscData.strings.done || "Restauración completada.",
              );
              setTimeout(function () {
                hideProgress("#ssc-progress-wrap");
                openRestoreDoneModal();
              }, 1000);
            },
            function (msg, lostContact) {
              stopSimulation();
              hideProgress("#ssc-progress-wrap");
              // Para restauración, perder contacto con el polling es señal
              // de éxito: la DB fue reemplazada y/o las sesiones destruidas.
              // Mostrar el modal de completado en lugar de solo una advertencia.
              if (lostContact) {
                openRestoreDoneModal();
              } else {
                showError(msg);
              }
            },
          );
        } else {
          stopSimulation();
          hideProgress("#ssc-progress-wrap");
          var msg =
            response && response.data && response.data.message
              ? response.data.message
              : sscData.strings.error;
          showError(msg);
        }
      })
      .fail(function () {
        stopSimulation();
        hideProgress("#ssc-progress-wrap");
        showError(sscData.strings.error);
      });
  });

  // ── Copiar log al portapapeles ────────────────────────────────────────
  $("#ssc-copy-log").on("click", function () {
    var $btn = $(this);
    var lines = [];

    // Cabecera.
    lines.push("Super Sheep Copy \u2014 Audit Log");
    lines.push("Exported: " + new Date().toISOString());
    lines.push("");
    lines.push(
      ["Date", "User", "IP", "Action", "Object", "Result", "Message"].join(
        "\t",
      ),
    );

    // Una línea por fila de la tabla.
    $(".ssc-log-table tbody tr").each(function () {
      var cells = [];
      $(this)
        .find("td")
        .each(function () {
          cells.push($(this).text().trim());
        });
      lines.push(cells.join("\t"));
    });

    var text = lines.join("\n");

    // Clipboard API (modern) con fallback a execCommand.
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(
        function () {
          sscShowCopied($btn);
        },
        function () {
          sscCopyFallback(text, $btn);
        },
      );
    } else {
      sscCopyFallback(text, $btn);
    }
  });

  function sscCopyFallback(text, $btn) {
    var $ta = $("<textarea>")
      .val(text)
      .css({ position: "fixed", top: "-9999px", left: "-9999px", opacity: 0 })
      .appendTo("body");
    $ta[0].select();
    try {
      document.execCommand("copy");
      sscShowCopied($btn);
    } catch (e) {
      /* silent \u2014 browser blocked clipboard */
    }
    $ta.remove();
  }

  function sscShowCopied($btn) {
    var original = $btn.text();
    $btn
      .text("\u2713 Copiado")
      .addClass("ssc-btn-copied")
      .prop("disabled", true);
    setTimeout(function () {
      $btn.text(original).removeClass("ssc-btn-copied").prop("disabled", false);
    }, 2000);
  }

  // ── Polling de estado de job ──────────────────────────────────────────

  /**
   * Consulta periódicamente el estado de un job en curso.
   *
   * El servidor ejecuta el respaldo/restauración en segundo plano (PHP sigue
   * corriendo tras fastcgi_finish_request). Esta función hace polling cada
   * pollDelay ms hasta recibir status === 'completed' o 'failed'.
   *
   * Maneja el caso especial 403: la restauración destruye todas las sesiones
   * al finalizar, por lo que el siguiente poll puede llegar con cookie inválida.
   * En ese caso se trata como éxito (el reload llevará al login).
   *
   * @param {string}   jobId         ID del job devuelto por el servidor.
   * @param {string}   statusAction  Acción AJAX (ssc_get_backup_status | ssc_get_restore_status).
   * @param {Function} onComplete    Callback llamado cuando status === 'completed'.
   * @param {Function} onFailed      Callback llamado con (msg, lostContact) cuando falla.
   *                                 lostContact=true: PHP puede seguir corriendo (incertidumbre).
   *                                 lostContact=false: el job reportó status='failed' (fallo real).
   */
  function startPolling(jobId, statusAction, onComplete, onFailed) {
    currentJobId = jobId;
    var failCount = 0;
    var notFoundCount = 0; // respuestas "job no encontrado" consecutivas
    var MAX_FAILS = 8; // errores de red consecutivos antes de rendirse
    var MAX_NOT_FOUND = 10; // "job no encontrado" consecutivos antes de asumir completado (restore)
    var startedAt = Date.now();

    pollInterval = setInterval(function () {
      // Límite de tiempo total de polling (15 min): si el proceso sigue 'running'
      // después de pollMaxMs es más probable que algo salió mal que que siga corriendo.
      if (Date.now() - startedAt > pollMaxMs) {
        clearInterval(pollInterval);
        pollInterval = null;
        currentJobId = null;
        onFailed(
          sscData.strings.errorPollTimeout || sscData.strings.error,
          true,
        );
        return;
      }

      $.post(sscData.ajaxUrl, {
        action: statusAction,
        nonce: sscData.nonce,
        job_id: jobId,
      })
        .done(function (response) {
          failCount = 0;
          // WordPress returns -1 for unauthenticated AJAX. For restore polls
          // this means the restore destroyed all sessions → treat as complete.
          if (
            (response === -1 ||
              response === 0 ||
              response === "-1" ||
              response === "0") &&
            "ssc_get_restore_status" === statusAction
          ) {
            clearInterval(pollInterval);
            pollInterval = null;
            currentJobId = null;
            onComplete({});
            return;
          }
          if (!response || !response.success || !response.data) {
            // 404 = job no encontrado. Puede que la DB fue reemplazada por un restore
            // y el transient de estado ya no existe. Si ocurre varias veces seguidas,
            // asumir que la operación terminó (o el lock es un residuo del site de origen).
            notFoundCount++;
            if (notFoundCount >= MAX_NOT_FOUND) {
              clearInterval(pollInterval);
              pollInterval = null;
              currentJobId = null;
              if ("ssc_get_restore_status" === statusAction) {
                onComplete({});
              } else {
                // Para backup: ocultar la barra y avisar sin error (el proceso pudo terminar).
                onFailed(
                  sscData.strings.errorPollTimeout || sscData.strings.error,
                  true,
                );
              }
            }
            return;
          }
          var state = response.data;
          notFoundCount = 0;
          if ("completed" === state.status) {
            clearInterval(pollInterval);
            pollInterval = null;
            currentJobId = null;
            onComplete(state);
          } else if ("failed" === state.status) {
            clearInterval(pollInterval);
            pollInterval = null;
            currentJobId = null;
            onFailed(state.message || sscData.strings.error, false);
          }
          // 'running' → continuar polling
        })
        .fail(function (jqXHR) {
          // 403 = sesión expirada (restauración destruyó todas las sesiones al finalizar).
          if (403 === jqXHR.status) {
            clearInterval(pollInterval);
            pollInterval = null;
            currentJobId = null;
            onComplete({});
            return;
          }
          // 404 durante polling de restauración = transient aún no existe en la DB
          // recién importada. Tratar como "job no encontrado" (notFoundCount),
          // no como error de red (failCount), para evitar mostrar la advertencia
          // antes de que el proceso haya terminado de escribir el estado final.
          if (
            404 === jqXHR.status &&
            "ssc_get_restore_status" === statusAction
          ) {
            notFoundCount++;
            if (notFoundCount >= MAX_NOT_FOUND) {
              clearInterval(pollInterval);
              pollInterval = null;
              currentJobId = null;
              onComplete({});
            }
            return;
          }
          // Otro error de red — contar fallos consecutivos.
          // El proceso PHP sigue corriendo en background aunque el polling falle;
          // si se agotan los intentos, avisar al usuario en lugar de mostrar error genérico.
          failCount++;
          if (failCount >= MAX_FAILS) {
            clearInterval(pollInterval);
            pollInterval = null;
            currentJobId = null;
            onFailed(
              sscData.strings.errorPollTimeout || sscData.strings.error,
              true,
            );
          }
        });
    }, pollDelay);
  }

  // ── Reanudar polling si había un job en curso al cargar la página ─────────
  // Ocurre cuando: (a) el usuario recargó la página durante una operación,
  // (b) el polling anterior falló por timeout de red pero PHP siguió corriendo.
  (function resumeRunningJobs() {
    if (sscData.runningBackupJobId) {
      $("#ssc-create-backup").prop("disabled", true);
      $("#ssc-progress-wrap").show();
      setBarWidth("#ssc-progress-bar", 90);
      $("#ssc-progress-bar").addClass("is-animating");
      $("#ssc-progress-label").text(
        sscData.strings.backupInProgress || "Respaldo en curso…",
      );

      startPolling(
        sscData.runningBackupJobId,
        "ssc_get_backup_status",
        function () {
          finishProgress(
            "#ssc-progress-bar",
            "#ssc-progress-label",
            sscData.strings.done || "¡Listo!",
          );
          setTimeout(function () {
            hideProgress("#ssc-progress-wrap");
            window.location.reload();
          }, 1200);
        },
        function (msg, lostContact) {
          stopSimulation();
          hideProgress("#ssc-progress-wrap");
          if (lostContact) {
            showWarning(msg);
          } else {
            showError(msg);
            $("#ssc-create-backup").prop("disabled", false);
          }
        },
      );
    }

    if (sscData.runningRestoreJobId) {
      $("#ssc-progress-wrap").show();
      setBarWidth("#ssc-progress-bar", 90);
      $("#ssc-progress-bar").addClass("is-animating");
      $("#ssc-progress-label").text(
        sscData.strings.restoreInProgress || "Restauración en curso…",
      );

      if (window.wp && window.wp.heartbeat) {
        window.wp.heartbeat.interval("slow");
      }

      startPolling(
        sscData.runningRestoreJobId,
        "ssc_get_restore_status",
        function () {
          finishProgress(
            "#ssc-progress-bar",
            "#ssc-progress-label",
            sscData.strings.done || "¡Listo!",
          );
          setTimeout(function () {
            hideProgress("#ssc-progress-wrap");
            openRestoreDoneModal();
          }, 1000);
        },
        function (msg, lostContact) {
          stopSimulation();
          hideProgress("#ssc-progress-wrap");
          if (lostContact) {
            openRestoreDoneModal();
          } else {
            showError(msg);
          }
        },
      );
    }
  })();

  // ── Motor de progreso simulado ────────────────────────────────────────

  /**
   * Arranca la animación de progreso simulada.
   *
   * Cada fase avanza el porcentaje durante su duración asignada,
   * luego pasa a la siguiente. La última fase se queda en su
   * porcentaje hasta que llega la respuesta real.
   *
   * @param {Array}  phases    Array de [pct, label, ms].
   * @param {string} wrapSel  Selector del contenedor.
   * @param {string} barSel   Selector de la barra interna.
   * @param {string} lblSel   Selector de la etiqueta.
   */
  function startSimulatedProgress(phases, wrapSel, barSel, lblSel) {
    stopSimulation();
    simulatedPercent = 0;

    $(wrapSel).show();
    $(barSel)
      .removeClass("is-done")
      .addClass("is-animating")
      .css("width", "0%");
    $(lblSel).removeClass("is-done");

    var phaseIndex = 0;

    function runPhase() {
      if (phaseIndex >= phases.length) {
        return;
      }

      var phase = phases[phaseIndex];
      var targetPct = phase[0];
      var label = phase[1];
      var duration = phase[2];
      var startPct = simulatedPercent;
      var range = targetPct - startPct;
      var startTime = Date.now();

      $(lblSel).text(label);

      // Última fase: solo actualizar label, no avanzar más.
      if (phaseIndex === phases.length - 1) {
        setBarWidth(barSel, targetPct);
        return;
      }

      simulateInterval = setInterval(function () {
        var elapsed = Date.now() - startTime;
        var progress = Math.min(elapsed / duration, 1);
        // Ease-out: avanza rápido al inicio, frena al final.
        var eased = 1 - Math.pow(1 - progress, 2);
        simulatedPercent = startPct + range * eased;
        setBarWidth(barSel, simulatedPercent);

        if (progress >= 1) {
          clearInterval(simulateInterval);
          simulateInterval = null;
          phaseIndex++;
          runPhase();
        }
      }, 80);
    }

    runPhase();
  }

  /**
   * Salta a 100%, cambia a verde y muestra el mensaje final.
   */
  function finishProgress(barSel, lblSel, message) {
    stopSimulation();
    setBarWidth(barSel, 100);
    $(barSel).removeClass("is-animating").addClass("is-done");
    $(lblSel).addClass("is-done").text(message);
  }

  /**
   * Detiene el temporizador de simulación.
   */
  function stopSimulation() {
    if (simulateInterval) {
      clearInterval(simulateInterval);
      simulateInterval = null;
    }
    if (pollInterval) {
      clearInterval(pollInterval);
      pollInterval = null;
    }
    currentJobId = null;
  }

  /**
   * Actualiza el ancho de la barra.
   */
  function setBarWidth(barSel, pct) {
    $(barSel).css("width", Math.min(100, Math.max(0, pct)) + "%");
  }

  function hideProgress(wrapSel) {
    $(wrapSel || "#ssc-progress-wrap").hide();
    // Reset para la próxima vez.
    $("#ssc-progress-bar, #ssc-restore-progress-bar")
      .removeClass("is-animating is-done")
      .css("width", "0%");
    $("#ssc-progress-label, #ssc-restore-progress-label").removeClass(
      "is-done",
    );
  }

  function showError(message) {
    var $notice = $(
      '<div class="notice notice-error is-dismissible"><p></p></div>',
    );
    $notice.find("p").text(message);
    var $container = $("#ssc-notices");
    if ($container.length) {
      $container.append($notice);
    } else {
      $(".ssc-wrap h1").first().after($notice);
    }
    $notice[0].scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function showWarning(message) {
    var $notice = $(
      '<div class="notice notice-warning is-dismissible"><p></p></div>',
    );
    $notice.find("p").text(message);
    var $container = $("#ssc-notices");
    if ($container.length) {
      $container.append($notice);
    } else {
      $(".ssc-wrap h1").first().after($notice);
    }
    $notice[0].scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  // ── Modal: restauración completada ─────────────────────────────────────
  function openRestoreDoneModal() {
    var loginUrl = sscData.loginUrl ? sscData.loginUrl : "/wp-login.php";
    var $modal = $("#ssc-restore-done-modal");
    if (!$modal.length) {
      $("body").append(
        '<div id="ssc-restore-done-modal" class="ssc-modal" aria-modal="true" role="alertdialog">' +
          '<div class="ssc-modal-backdrop"></div>' +
          '<div class="ssc-modal-dialog ssc-restore-done-dialog">' +
          '<div class="ssc-modal-header">' +
          '<h3 class="ssc-modal-title">' +
          '<svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="#059669" stroke-width="1.5"/><path d="M5 8l2 2 4-4" stroke="#059669" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
          "Restauración completada</h3></div>" +
          '<div class="ssc-modal-body ssc-restore-done-body">' +
          "<p>El sitio ha sido restaurado correctamente. Por seguridad, tu sesión ha sido cerrada.</p>" +
          "<p>Necesitarás autenticarte de nuevo para continuar trabajando en el administrador.</p>" +
          '<div class="ssc-restore-done-actions">' +
          '<a id="ssc-restore-done-login" href="' +
          loginUrl +
          '" class="ssc-btn-success">Ir al inicio de sesión</a>' +
          "</div></div></div></div>",
      );
      $modal = $("#ssc-restore-done-modal");
    }
    $("#ssc-restore-done-login").attr("href", loginUrl);
    $modal.removeAttr("hidden").css("display", "flex");
    $("#ssc-restore-done-login").trigger("focus");
  }
})(jQuery);
