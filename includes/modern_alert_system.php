<?php
if (!function_exists('renderModernAlertSystem')) {
    function renderModernAlertSystem(): void
    {
        ?>
        <style>
            .modern-alert-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                backdrop-filter: blur(4px);
                z-index: 2000;
                animation: modernAlertFadeIn 0.25s ease;
            }

            .modern-alert-overlay.show {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .modern-alert-content {
                background: #fff;
                border-radius: 20px;
                padding: 36px 28px;
                width: min(460px, 100%);
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.28);
                position: relative;
                text-align: center;
                animation: modernAlertSlideUp 0.28s ease;
            }

            .modern-alert-close {
                position: absolute;
                top: 14px;
                right: 14px;
                border: none;
                background: transparent;
                color: #8a8a8a;
                font-size: 1.4rem;
                line-height: 1;
                cursor: pointer;
            }

            .modern-alert-icon {
                width: 80px;
                height: 80px;
                margin: 0 auto 14px auto;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 2.5rem;
            }

            .modern-alert-icon.success {
                background: rgba(40, 167, 69, 0.13);
                color: #28a745;
            }

            .modern-alert-icon.error {
                background: rgba(220, 53, 69, 0.13);
                color: #dc3545;
            }

            .modern-alert-icon.warning {
                background: rgba(255, 193, 7, 0.2);
                color: #a17600;
            }

            .modern-alert-icon.confirm {
                background: rgba(128, 0, 0, 0.12);
                color: #800000;
            }

            .modern-alert-title {
                margin: 0 0 12px 0;
                font-size: 1.45rem;
                font-weight: 700;
                color: #2f2f2f;
            }

            .modern-alert-message {
                margin: 0;
                color: #636363;
                line-height: 1.6;
                word-break: break-word;
            }

            .modern-alert-actions {
                margin-top: 22px;
                display: flex;
                justify-content: center;
                gap: 10px;
                flex-wrap: wrap;
            }

            .modern-alert-actions .btn {
                min-width: 110px;
                border-radius: 10px;
                font-weight: 600;
            }

            .modern-alert-actions .btn-primary {
                background: #800000;
                border-color: #800000;
            }

            .modern-alert-actions .btn-primary:hover {
                background: #a00000;
                border-color: #a00000;
            }

            @keyframes modernAlertFadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }

            @keyframes modernAlertSlideUp {
                from {
                    opacity: 0;
                    transform: translateY(20px) scale(0.96);
                }
                to {
                    opacity: 1;
                    transform: translateY(0) scale(1);
                }
            }
        </style>

        <div id="modernAlertModal" class="modern-alert-overlay" aria-hidden="true">
            <div class="modern-alert-content" role="dialog" aria-modal="true" aria-labelledby="modernAlertTitle" aria-describedby="modernAlertMessage">
                <button type="button" class="modern-alert-close" onclick="closeModernAlert()">&times;</button>
                <div id="modernAlertIcon" class="modern-alert-icon"></div>
                <h2 id="modernAlertTitle" class="modern-alert-title"></h2>
                <p id="modernAlertMessage" class="modern-alert-message"></p>
                <div id="modernAlertActions" class="modern-alert-actions"></div>
            </div>
        </div>

        <script>
            (function() {
                const modal = document.getElementById('modernAlertModal');
                const icon = document.getElementById('modernAlertIcon');
                const title = document.getElementById('modernAlertTitle');
                const message = document.getElementById('modernAlertMessage');
                const actions = document.getElementById('modernAlertActions');

                function getTypeConfig(type) {
                    const map = {
                        success: { icon: 'fa-check-circle', title: 'Success', iconClass: 'success' },
                        error: { icon: 'fa-exclamation-circle', title: 'Error', iconClass: 'error' },
                        warning: { icon: 'fa-exclamation-triangle', title: 'Warning', iconClass: 'warning' },
                        confirm: { icon: 'fa-question-circle', title: 'Confirm Action', iconClass: 'confirm' }
                    };
                    return map[type] || map.warning;
                }

                window.showModernAlert = function(type, alertTitle, alertMessage, options = {}) {
                    const cfg = getTypeConfig(type);
                    const titleText = alertTitle || cfg.title;
                    const msgText = alertMessage || '';
                    const autoCloseMs = Number(options.autoCloseMs || 0);

                    icon.className = 'modern-alert-icon ' + cfg.iconClass;
                    icon.innerHTML = '<i class="fas ' + cfg.icon + '"></i>';
                    title.textContent = titleText;
                    message.textContent = msgText;

                    actions.innerHTML = '';
                    const okBtn = document.createElement('button');
                    okBtn.type = 'button';
                    okBtn.className = 'btn btn-primary';
                    okBtn.textContent = options.okText || 'OK';
                    okBtn.addEventListener('click', function() {
                        closeModernAlert();
                        if (typeof options.onOk === 'function') {
                            options.onOk();
                        }
                    });
                    actions.appendChild(okBtn);

                    modal.classList.add('show');

                    if (autoCloseMs > 0) {
                        window.setTimeout(closeModernAlert, autoCloseMs);
                    }
                };

                window.showModernConfirm = function(alertTitle, alertMessage, onConfirm, options = {}) {
                    const cfg = getTypeConfig('confirm');
                    icon.className = 'modern-alert-icon ' + cfg.iconClass;
                    icon.innerHTML = '<i class="fas ' + cfg.icon + '"></i>';
                    title.textContent = alertTitle || cfg.title;
                    message.textContent = alertMessage || '';

                    actions.innerHTML = '';

                    const cancelBtn = document.createElement('button');
                    cancelBtn.type = 'button';
                    cancelBtn.className = 'btn btn-secondary';
                    cancelBtn.textContent = options.cancelText || 'Cancel';
                    cancelBtn.addEventListener('click', closeModernAlert);

                    const confirmBtn = document.createElement('button');
                    confirmBtn.type = 'button';
                    confirmBtn.className = 'btn btn-primary';
                    confirmBtn.textContent = options.confirmText || 'Confirm';
                    confirmBtn.addEventListener('click', function() {
                        closeModernAlert();
                        if (typeof onConfirm === 'function') {
                            onConfirm();
                        }
                    });

                    actions.appendChild(cancelBtn);
                    actions.appendChild(confirmBtn);
                    modal.classList.add('show');
                };

                window.closeModernAlert = function() {
                    modal.classList.remove('show');
                };

                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeModernAlert();
                    }
                });

                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        closeModernAlert();
                    }
                });
            })();
        </script>
        <?php
    }
}
?>
