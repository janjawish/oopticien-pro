document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.querySelector('[data-sidebar]');
  const overlay = document.querySelector('[data-sidebar-overlay]');
  const toggleSidebar = () => {
    sidebar?.classList.toggle('open');
    overlay?.classList.toggle('visible');
  };
  document.querySelector('[data-menu-toggle]')?.addEventListener('click', toggleSidebar);
  overlay?.addEventListener('click', toggleSidebar);

  const mainNav = sidebar?.querySelector('.main-nav');
  if (mainNav) {
    const scrollKey = 'oopticien.sidebar.scrollTop';
    try {
      const savedScroll = window.sessionStorage.getItem(scrollKey);
      if (savedScroll !== null) {
        mainNav.scrollTop = Number.parseInt(savedScroll, 10) || 0;
      } else {
        const activeLink = mainNav.querySelector('a.active');
        if (activeLink) {
          mainNav.scrollTop = Math.max(0, activeLink.offsetTop - (mainNav.clientHeight / 2));
        }
      }
      const saveMenuPosition = () => {
        window.sessionStorage.setItem(scrollKey, String(mainNav.scrollTop));
      };
      mainNav.addEventListener('scroll', saveMenuPosition, {passive: true});
      mainNav.querySelectorAll('a').forEach((link) => link.addEventListener('click', saveMenuPosition));
    } catch {
      // La navigation reste utilisable si le stockage de session est désactivé.
    }
  }

  document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const input = button.closest('.password-wrap')?.querySelector('[data-password]');
      if (!input) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      button.textContent = show ? 'Masquer' : 'Afficher';
    });
  });

  document.querySelectorAll('[data-confirm]').forEach((element) => {
    element.addEventListener('click', (event) => {
      if (!window.confirm(element.dataset.confirm || 'Confirmer cette action ?')) {
        event.preventDefault();
      }
    });
  });

  document.querySelectorAll('[data-nir-reveal]').forEach((button) => {
    button.addEventListener('click', async () => {
      if (button.dataset.revealed === 'true') return;
      button.disabled = true;
      const body = new URLSearchParams({
        client_id: button.dataset.clientId || '',
        csrf_token: button.dataset.csrf || '',
      });
      try {
        const response = await fetch(button.dataset.endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body,
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) throw new Error(payload.error || 'Accès refusé');
        const target = document.querySelector('#client-nir');
        if (target) target.textContent = payload.nir;
        button.dataset.revealed = 'true';
        button.textContent = 'Affiché et journalisé';
      } catch (error) {
        window.alert(error.message || 'Impossible d’afficher le NIR.');
        button.disabled = false;
      }
    });
  });

  const amountForm = document.querySelector('[data-amount-form]');
  if (amountForm) {
    const ro = amountForm.querySelector('[name="ro_amount"]');
    const rc = amountForm.querySelector('[name="rc_amount"]');
    const rac = amountForm.querySelector('[name="rac_amount"]');
    const total = amountForm.querySelector('[name="total_amount"]');
    const hint = amountForm.querySelector('[data-total-hint]');
    const parse = (input) => Number.parseFloat(String(input?.value || '0').replace(',', '.')) || 0;
    const updateTotal = () => {
      const expected = Math.round((parse(ro) + parse(rc) + parse(rac)) * 100) / 100;
      const declared = parse(total);
      if (hint) {
        hint.textContent = `Somme calculée : ${expected.toLocaleString('fr-FR', {minimumFractionDigits: 2})} €`;
        hint.classList.toggle('text-danger', Math.abs(expected - declared) > 0.01);
      }
    };
    [ro, rc, rac, total].forEach((input) => input?.addEventListener('input', updateTotal));
    updateTotal();
  }

  document.querySelectorAll('[data-auto-dismiss]').forEach((alert) => {
    window.setTimeout(() => alert.remove(), 5000);
  });

  document.querySelectorAll('[data-payment-row]').forEach((row) => {
    const method = row.querySelector('[data-payment-method]');
    const status = row.querySelector('[data-payment-status]');
    const chequeOption = status?.querySelector('[data-cheque-only="1"]');
    if (!method || !status || !chequeOption) return;
    const updateChequeStatus = () => {
      const isCheque = method.value === 'cheque';
      chequeOption.hidden = !isCheque;
      chequeOption.disabled = !isCheque;
      if (!isCheque && status.value === 'cheque_caution') status.value = 'attendu';
    };
    method.addEventListener('change', updateChequeStatus);
    updateChequeStatus();
  });

  document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
      const target = document.querySelector(button.dataset.copyTarget);
      if (!target) return;
      try {
        await navigator.clipboard.writeText(target.innerText);
        const oldLabel = button.textContent;
        button.textContent = 'Copié';
        window.setTimeout(() => { button.textContent = oldLabel; }, 1800);
      } catch {
        window.alert('Copie impossible. Sélectionnez le texte manuellement.');
      }
    });
  });

  const documentDossier = document.querySelector('[data-document-dossier]');
  const documentClient = document.querySelector('[data-document-client]');
  if (documentDossier && documentClient) {
    const selectDossierClient = () => {
      const clientId = documentDossier.selectedOptions[0]?.dataset.client;
      if (clientId) documentClient.value = clientId;
    };
    documentDossier.addEventListener('change', selectDossierClient);
    selectDossierClient();
  }

  document.querySelectorAll('[data-beneficiary-picker]').forEach((picker) => {
    const form = picker.closest('form');
    const owner = form?.querySelector('[data-credit-owner]');
    const search = picker.querySelector('[data-beneficiary-search]');
    const count = picker.querySelector('[data-beneficiary-count]');
    const options = [...picker.querySelectorAll('[data-beneficiary-option]')];
    const normalize = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const update = () => {
      const query = normalize(search?.value);
      const ownerId = owner?.value || '';
      let selected = 0;
      options.forEach((option) => {
        const checkbox = option.querySelector('input[type="checkbox"]');
        const isOwner = option.dataset.clientId === ownerId;
        if (isOwner && checkbox) checkbox.checked = false;
        option.hidden = isOwner || (query !== '' && !normalize(option.textContent).includes(query));
        if (checkbox?.checked) selected++;
      });
      if (count) count.textContent = `${selected} bénéficiaire${selected > 1 ? 's' : ''} supplémentaire${selected > 1 ? 's' : ''}`;
    };
    search?.addEventListener('input', update);
    owner?.addEventListener('change', update);
    options.forEach((option) => option.querySelector('input')?.addEventListener('change', update));
    update();
  });

  const guardedForms = [...document.querySelectorAll('form[data-unsaved-warning]')];
  if (guardedForms.length) {
    const initialStates = new Map();
    const submittedForms = new WeakSet();
    const formState = (form) => {
      const values = [];
      new FormData(form).forEach((value, key) => {
        if (value instanceof File) {
          values.push([key, value.name, value.size, value.lastModified]);
        } else {
          values.push([key, String(value)]);
        }
      });
      return JSON.stringify(values);
    };

    guardedForms.forEach((form) => {
      initialStates.set(form, formState(form));
      form.addEventListener('submit', () => submittedForms.add(form));
    });

    window.addEventListener('beforeunload', (event) => {
      const hasUnsavedChanges = guardedForms.some(
        (form) => !submittedForms.has(form) && initialStates.get(form) !== formState(form)
      );
      if (!hasUnsavedChanges) return;
      event.preventDefault();
      event.returnValue = '';
    });
  }

  const assistantWidget = document.querySelector('[data-assistant-widget]');
  if (assistantWidget) {
    const panel = assistantWidget.querySelector('[data-assistant-panel]');
    const toggle = assistantWidget.querySelector('[data-assistant-toggle]');
    const close = assistantWidget.querySelector('[data-assistant-close]');
    const form = assistantWidget.querySelector('[data-assistant-form]');
    const input = form?.querySelector('textarea[name="prompt"]');
    const submit = form?.querySelector('button[type="submit"]');
    const messages = assistantWidget.querySelector('[data-assistant-messages]');
    const csrf = form?.querySelector('input[name="csrf_token"]');

    const setOpen = (open) => {
      if (!panel || !toggle) return;
      panel.hidden = !open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? 'Fermer l’assistant' : 'Ouvrir l’assistant');
      if (open) window.setTimeout(() => input?.focus(), 50);
    };
    const scrollMessages = () => {
      if (messages) messages.scrollTop = messages.scrollHeight;
    };
    const appendMessage = (content, type, isHtml = false) => {
      if (!messages) return null;
      const article = document.createElement('div');
      article.className = `assistant-widget-message is-${type}`;
      const bubble = document.createElement('div');
      if (isHtml) bubble.innerHTML = content;
      else bubble.textContent = content;
      article.appendChild(bubble);
      messages.appendChild(article);
      scrollMessages();
      return article;
    };
    const appendChoices = (target, choices) => {
      const bubble = target?.querySelector('div');
      if (!bubble || !Array.isArray(choices) || choices.length === 0) return;
      const list = document.createElement('div');
      list.className = 'assistant-widget-choices';
      choices.forEach((choice) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'assistant-widget-choice';
        button.dataset.assistantChoice = String(choice.value || '');
        const label = document.createElement('strong');
        label.textContent = String(choice.label || choice.value || 'Choisir');
        const details = document.createElement('small');
        details.textContent = String(choice.details || '');
        button.append(label, details);
        list.appendChild(button);
      });
      bubble.appendChild(list);
      scrollMessages();
    };
    const sendPrompt = async (prompt) => {
      const value = String(prompt || '').trim();
      if (!value || !form || !csrf || !submit) return;
      appendMessage(value, 'user');
      if (input) input.value = '';
      submit.disabled = true;
      const loading = appendMessage('Je vérifie…', 'assistant');
      loading?.classList.add('is-loading');
      try {
        const body = new URLSearchParams({prompt: value, csrf_token: csrf.value});
        const response = await fetch(assistantWidget.dataset.endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
          body,
        });
        const payload = await response.json().catch(() => null);
        loading?.remove();
        if (!response.ok || !payload?.ok) throw new Error(payload?.error || 'Réponse de l’assistant indisponible.');
        const answer = appendMessage(payload.response_html || 'Demande traitée.', 'assistant', true);
        appendChoices(answer, payload.choices);
        if (payload.redirect_url) {
          window.setTimeout(() => window.location.assign(payload.redirect_url), 550);
        }
      } catch (error) {
        loading?.remove();
        appendMessage(error.message || 'Une erreur est survenue. Aucune donnée n’a été modifiée.', 'assistant');
      } finally {
        submit.disabled = false;
        input?.focus();
      }
    };

    toggle?.addEventListener('click', () => setOpen(Boolean(panel?.hidden)));
    close?.addEventListener('click', () => setOpen(false));
    form?.addEventListener('submit', (event) => {
      event.preventDefault();
      sendPrompt(input?.value);
    });
    input?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        form?.requestSubmit();
      }
    });
    assistantWidget.addEventListener('click', (event) => {
      const promptButton = event.target.closest('[data-assistant-prompt]');
      const choiceButton = event.target.closest('[data-assistant-choice]');
      if (promptButton) sendPrompt(promptButton.dataset.assistantPrompt);
      if (choiceButton) sendPrompt(choiceButton.dataset.assistantChoice);
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && panel && !panel.hidden) setOpen(false);
    });
  }
});
