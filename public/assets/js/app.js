/**
 * Comportamentos de interface do painel.
 * Regras de negócio ficam no backend; aqui só há UX.
 */
(() => {
    'use strict';

    const csrf = {
        name: document.querySelector('meta[name="csrf-token-name"]')?.content,
        hash: document.querySelector('meta[name="csrf-token-hash"]')?.content,
        header: document.querySelector('meta[name="csrf-header"]')?.content,
    };

    /**
     * GET em JSON na API interna, com tratamento de erro explícito.
     */
    async function fetchJson(url) {
        const response = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`Falha na requisição (${response.status})`);
        }

        return response.json();
    }

    window.App = { csrf, fetchJson };

    // Confirmação de ações destrutivas: <form data-confirm="Mensagem">
    const modalEl = document.getElementById('confirmModal');

    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        let pendingForm = null;

        document.addEventListener('submit', (event) => {
            const form = event.target;

            if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();
            pendingForm = form;
            modalEl.querySelector('[data-confirm-message]').textContent = form.dataset.confirm;
            modal.show();
        });

        modalEl.querySelector('[data-confirm-accept]').addEventListener('click', () => {
            if (pendingForm) {
                pendingForm.dataset.confirmed = '1';
                pendingForm.submit();
            }
            modal.hide();
        });
    }

    // Lista dinâmica de URLs de imagens no formulário de produto.
    document.querySelectorAll('[data-image-list]').forEach((container) => {
        const template = container.querySelector('template');
        const list = container.querySelector('[data-image-items]');
        const max = Number(container.dataset.max || 12);

        container.querySelector('[data-image-add]')?.addEventListener('click', () => {
            if (list.children.length >= max) {
                return;
            }
            list.appendChild(template.content.cloneNode(true));
            list.lastElementChild?.querySelector('input')?.focus();
        });

        list.addEventListener('click', (event) => {
            const button = event.target.closest('[data-image-remove]');

            if (button) {
                button.closest('[data-image-item]')?.remove();
            }
        });
    });

    // Simulador de preços: o cálculo é feito no servidor; aqui só trocamos o
    // fragmento de resultado sem recarregar a página.
    document.querySelectorAll('[data-pricing-simulator]').forEach((form) => {
        const target = document.querySelector('[data-pricing-result]');
        let timer = null;
        let controller = null;

        const run = async () => {
            const params = new URLSearchParams();
            new FormData(form).forEach((value, key) => {
                if (String(value).trim() !== '') {
                    params.append(key, value);
                }
            });
            const query = params.toString();
            controller?.abort();
            controller = new AbortController();

            try {
                const response = await fetch(`${form.dataset.endpoint}?${query}`, {
                    headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });

                if (!response.ok && response.status !== 422) {
                    throw new Error(`Falha na requisição (${response.status})`);
                }

                target.innerHTML = await response.text();
                window.history.replaceState(null, '', `${form.action}?${query}`);
            } catch (error) {
                if (error.name === 'AbortError') {
                    return;
                }
                target.replaceChildren();
                const failure = document.createElement('div');
                failure.className = 'alert alert-danger small mb-0';
                failure.textContent = 'Não foi possível calcular agora. Tente novamente.';
                target.appendChild(failure);
                console.error(error);
            }
        };

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            run();
        });

        form.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(run, 400);
        });
    });

    // Prévia de anúncio: mexeu no formulário, precisa atualizar a prévia antes de confirmar.
    document.querySelectorAll('[data-listing-preview]').forEach((form) => {
        const confirm = document.querySelector('[data-listing-confirm]');
        const hint = document.querySelector('[data-listing-stale]');

        form.addEventListener('input', () => {
            confirm?.setAttribute('disabled', 'disabled');
            hint?.classList.remove('d-none');
        });
    });

    // Autocomplete de produto (usa GET /api/products?q=).
    document.querySelectorAll('[data-product-autocomplete]').forEach((wrapper) => {
        const input = wrapper.querySelector('input[type="search"]');
        const hidden = wrapper.querySelector('input[type="hidden"]');
        const menu = wrapper.querySelector('.autocomplete-menu');
        const clear = wrapper.querySelector('[data-autocomplete-clear]');
        let timer = null;

        const close = () => {
            menu.classList.add('d-none');
            menu.replaceChildren();
        };

        const render = (items) => {
            menu.replaceChildren();

            if (items.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'list-group-item small text-body-secondary';
                empty.textContent = 'Nenhum produto encontrado.';
                menu.appendChild(empty);
            }

            items.forEach((item) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'list-group-item list-group-item-action small';
                button.textContent = `${item.sku} — ${item.name}`;
                button.addEventListener('click', () => {
                    hidden.value = item.id;
                    input.value = `${item.sku} — ${item.name}`;
                    close();
                });
                menu.appendChild(button);
            });
            menu.classList.remove('d-none');
        };

        input.addEventListener('input', () => {
            hidden.value = '';
            clearTimeout(timer);
            const term = input.value.trim();

            if (term.length < 2) {
                close();
                return;
            }

            timer = setTimeout(async () => {
                try {
                    const payload = await fetchJson(`${wrapper.dataset.endpoint}?q=${encodeURIComponent(term)}&per_page=10`);
                    render(payload.data ?? []);
                } catch (error) {
                    menu.replaceChildren();
                    const failure = document.createElement('div');
                    failure.className = 'list-group-item small text-danger';
                    failure.textContent = 'Não foi possível buscar produtos.';
                    menu.appendChild(failure);
                    menu.classList.remove('d-none');
                    console.error(error);
                }
            }, 250);
        });

        clear?.addEventListener('click', () => {
            hidden.value = '';
            input.value = '';
            close();
        });

        document.addEventListener('click', (event) => {
            if (!wrapper.contains(event.target)) {
                close();
            }
        });
    });
})();
