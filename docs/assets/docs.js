(() => {
  'use strict';

  const documents = typeof pages !== 'undefined' && Array.isArray(pages) ? pages : [];
  if (!documents.length) return;
  const byId = new Map(documents.map(page => [page.id, page]));
  const home = byId.get('inicio') || documents[0];
  const $ = id => document.getElementById(id);
  const decode = value => {
    try { return decodeURIComponent(value); } catch { return value; }
  };
  const fold = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const escape = value => String(value || '').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[character]);
  const routeHref = (page, anchor = '') => '#' + encodeURIComponent(page.id) + (anchor ? '/' + encodeURIComponent(anchor) : '');
  const normalizePath = path => {
    const result = [];
    for (const part of path.replace(/\\/g, '/').split('/')) {
      if (!part || part === '.') continue;
      if (part === '..' && result.length && result[result.length - 1] !== '..') result.pop();
      else if (part !== '..' || !path.startsWith('/')) result.push(part);
    }
    return result.join('/');
  };
  const sources = new Map(documents.filter(page => page.source).map(page => [normalizePath(page.source), page]));
  const sourceHref = path => {
    const normalized = normalizePath(path);
    if (location.hostname === 'mmhospedagem.github.io') {
      return 'https://github.com/mmhospedagem/mvo-whats-php/blob/main/' + normalized;
    }
    return normalized.startsWith('docs/') ? normalized.slice(5) : '../' + normalized;
  };
  const pageText = new Map(documents.map(page => {
    // Template contents are inert: building the search index does not load images or run markup.
    const template = document.createElement('template');
    template.innerHTML = page.body;
    return [page.id, (template.content.textContent || '').replace(/\s+/g, ' ').trim()];
  }));
  const searchable = documents.map(page => ({
    page, text: pageText.get(page.id), title: fold(page.title),
    normalized: fold(page.title + ' ' + page.group + ' ' + pageText.get(page.id))
  }));

  const phpKeywords = new Set((
    'abstract and array as bool break callable case catch class clone const continue declare default die do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile enum eval exit extends false final finally float fn for foreach from function global goto if implements include include_once instanceof insteadof int interface isset iterable list match mixed namespace never new null object or parent print private protected public readonly require require_once return self static string switch throw trait true try unset use var void while xor yield'
  ).split(' '));

  function phpTokens(text) {
    const tokens = [];
    const wordStart = character => /[A-Za-z_\u0080-\uFFFF]/.test(character);
    const wordPart = character => /[A-Za-z_0-9\u0080-\uFFFF]/.test(character);
    const add = (start, end, kind = null) => {
      const value = text.slice(start, end);
      const previous = tokens[tokens.length - 1];
      if (!kind && previous && !previous.kind) previous.text += value;
      else tokens.push({ text: value, kind });
    };
    for (let index = 0; index < text.length;) {
      const start = index;
      if (text.startsWith('//', index)) {
        index += 2;
        while (index < text.length && text[index] !== '\n' && text[index] !== '\r') index++;
        add(start, index, 'comment');
      } else if (text.startsWith('/*', index)) {
        const end = text.indexOf('*/', index + 2);
        index = end < 0 ? text.length : end + 2;
        add(start, index, 'comment');
      } else if (text[index] === '"' || text[index] === "'") {
        const quote = text[index++];
        while (index < text.length) {
          if (text[index] === '\\') { index = Math.min(text.length, index + 2); continue; }
          if (text[index++] === quote) break;
        }
        add(start, index, 'string');
      } else if (text[index] === '$' && index + 1 < text.length && wordStart(text[index + 1])) {
        index += 2;
        while (index < text.length && wordPart(text[index])) index++;
        add(start, index, 'variable');
      } else if (text.startsWith('<?php', index) && (index + 5 === text.length || /\s/.test(text[index + 5]))) {
        index += 5;
        add(start, index, 'keyword');
      } else if (text.startsWith('<?=', index) || text.startsWith('?>', index)) {
        index += text.startsWith('<?=', index) ? 3 : 2;
        add(start, index, 'keyword');
      } else if (wordStart(text[index])) {
        index++;
        while (index < text.length && wordPart(text[index])) index++;
        add(start, index, phpKeywords.has(text.slice(start, index).toLowerCase()) ? 'keyword' : null);
      } else add(start, ++index);
    }
    return tokens;
  }

  function highlightPhp(code) {
    const text = code.textContent || '';
    const fragment = document.createDocumentFragment();
    for (const token of phpTokens(text)) {
      const node = document.createTextNode(token.text);
      if (!token.kind) fragment.append(node);
      else {
        const span = document.createElement('span');
        span.className = 'token-' + token.kind;
        span.append(node);
        fragment.append(span);
      }
    }
    code.replaceChildren(fragment);
    // Decoration must never change a byte of the text exposed to the copy action.
    if (code.textContent !== text) code.textContent = text;
  }

  function init() {
    const article = $('article');
    const content = $('content');
    const sidebar = $('sidebar');
    const menu = $('menu');
    const overlay = $('sidebar-overlay');
    const dialog = $('search-dialog');
    const search = $('search');
    const results = $('results');
    if (!article || !content) return;
    let active = null;
    let searchIsOpen = false;
    let restoreSearchFocus = null;
    let searchLinks = [];
    let outlineObserver = null;
    let sidebarIsOpen = false;
    let resizeFrame = null;
    const copyTimers = new WeakMap();
    const feedback = $('copy-feedback') || document.createElement('div');
    feedback.id = 'copy-feedback';
    feedback.className = 'sr-only';
    feedback.setAttribute('role', 'status');
    feedback.setAttribute('aria-live', 'polite');
    feedback.setAttribute('aria-atomic', 'true');
    if (!feedback.isConnected) document.body.append(feedback);

    function bindLogo() {
      const assets = [
        ['[data-mvo-logo]', typeof mvoLogo === 'undefined' ? null : mvoLogo],
        ['[data-mvo-brand]', typeof mvoBrand === 'undefined' ? null : mvoBrand],
      ];
      assets.forEach(([selector, data]) => {
        if (!data) return;
        document.querySelectorAll(selector).forEach(element => {
          if (element.tagName === 'LINK') element.href = data;
          else if (element.tagName === 'IMG') element.src = data;
        });
      });
    }

    function syncHeaderHeight() {
      const header = $('site-header');
      if (header) document.documentElement.style.setProperty('--header-height', header.offsetHeight + 'px');
    }

    function navigation() {
      const nav = $('nav');
      if (!nav) return;
      const groups = new Map();
      documents.forEach(page => {
        if (!groups.has(page.group)) groups.set(page.group, []);
        groups.get(page.group).push(page);
      });
      nav.innerHTML = [...groups].map(([group, entries], index) =>
        `<details class="nav-group"${group === home.group ? ' open' : ''}><summary id="nav-group-${index}"><span>${escape(group)}</span><svg class="nav-chevron" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="m6 4 4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></summary><div class="nav-items" aria-labelledby="nav-group-${index}">` +
        entries.map(page => `<a href="${routeHref(page)}" data-page="${escape(page.id)}">${escape(page.title)}</a>`).join('') + '</div></details>'
      ).join('');
    }

    function revealActiveNavigation() {
      const link = sidebar?.querySelector('a[data-page].active');
      if (!link) return;
      const group = link.closest('details.nav-group');
      if (group) group.open = true;
      requestAnimationFrame(() => {
        if (!link.isConnected || !sidebar?.clientHeight) return;
        const bounds = sidebar.getBoundingClientRect();
        const current = link.getBoundingClientRect();
        const top = bounds.top + sidebar.clientTop + 12;
        const bottom = bounds.top + sidebar.clientTop + sidebar.clientHeight - 12;
        // Adjust only the navigation scroller; the reader's document position stays put.
        if (current.top < top) sidebar.scrollTop += current.top - top;
        else if (current.bottom > bottom) sidebar.scrollTop += current.bottom - bottom;
      });
    }

    function setSidebar(open, restore = false) {
      sidebarIsOpen = Boolean(open && sidebar);
      sidebar?.classList.toggle('open', sidebarIsOpen);
      document.body.classList.toggle('sidebar-open', sidebarIsOpen);
      menu?.setAttribute('aria-expanded', String(sidebarIsOpen));
      if (menu) menu.setAttribute('aria-label', sidebarIsOpen ? 'Fechar navegação' : 'Abrir navegação');
      if (overlay) overlay.hidden = !sidebarIsOpen;
      if (sidebarIsOpen) {
        revealActiveNavigation();
        requestAnimationFrame(() => (sidebar.querySelector('a.active') || sidebar.querySelector('summary') || sidebar.querySelector('a'))?.focus({ preventScroll: true }));
      } else if (restore) menu?.focus();
    }

    function focusContent(scroll = false) {
      if (scroll) content.scrollIntoView({ block: 'start' });
      content.focus({ preventScroll: true });
    }

    function resolvePath(path, page) {
      const decoded = decode(path).replace(/\\/g, '/');
      const directory = page.source ? page.source.slice(0, page.source.lastIndexOf('/') + 1) : '';
      const relative = normalizePath(decoded.startsWith('/') ? decoded : directory + decoded);
      const root = normalizePath(decoded);
      // README links often explicitly include docs/. Exact source matches avoid basename ambiguity.
      return { path: relative, page: sources.get(relative) || sources.get(root) || null };
    }

    function rewriteLinks(container, page, outline = false) {
      container.querySelectorAll('a[href]').forEach(link => {
        const href = link.getAttribute('href');
        if (!href || /^[a-z][a-z\d+.-]*:/i.test(href) || href.startsWith('//')) return;
        if (href.startsWith('#')) {
          const fragment = href.slice(1);
          const slash = fragment.indexOf('/');
          const target = byId.get(decode(slash < 0 ? fragment : fragment.slice(0, slash)));
          link.href = !outline && target ? routeHref(target, slash < 0 ? '' : decode(fragment.slice(slash + 1))) : routeHref(page, decode(fragment));
          return;
        }
        const hash = href.indexOf('#');
        const file = hash < 0 ? href : href.slice(0, hash);
        const anchor = hash < 0 ? '' : decode(href.slice(hash + 1));
        const query = file.indexOf('?');
        const path = query < 0 ? file : file.slice(0, query);
        const resolved = resolvePath(path, page);
        link.href = resolved.page ? routeHref(resolved.page, anchor) : sourceHref(resolved.path) + (query < 0 ? '' : file.slice(query)) + (anchor ? '#' + encodeURIComponent(anchor) : '');
      });
      if (outline) return;
      container.querySelectorAll('img[src]:not([data-mvo-logo])').forEach(image => {
        const src = image.getAttribute('src');
        if (src && !/^[a-z][a-z\d+.-]*:/i.test(src) && !src.startsWith('//')) image.src = sourceHref(resolvePath(src, page).path);
      });
    }

    function setCopyLabel(button, text) {
      let label = button.querySelector('[data-copy-label]');
      if (!label) {
        label = document.createElement('span');
        label.setAttribute('data-copy-label', '');
        [...button.childNodes].forEach(node => { if (node.nodeType === Node.TEXT_NODE) node.remove(); });
        button.append(label);
      }
      label.textContent = text;
    }

    async function copyCode(button) {
      const target = $(button.dataset.copyTarget);
      if (!target || !article.contains(target)) return;
      const code = target.tagName === 'CODE' ? target : target.querySelector('code') || target;
      const text = code.textContent || '';
      const original = button.dataset.copyOriginal || button.textContent.trim() || 'Copiar';
      button.dataset.copyOriginal = original;
      clearTimeout(copyTimers.get(button));
      let message;
      try {
        if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
        let deadline;
        try {
          await Promise.race([
            navigator.clipboard.writeText(text),
            new Promise((resolve, reject) => { deadline = setTimeout(() => reject(new Error('Clipboard timed out')), 800); })
          ]);
        } finally { clearTimeout(deadline); }
        message = 'Código copiado.';
        setCopyLabel(button, 'Copiado');
      } catch {
        if (!code.isConnected) return;
        const selection = window.getSelection();
        if (selection) {
          const range = document.createRange();
          range.selectNodeContents(code);
          selection.removeAllRanges();
          selection.addRange(range);
          message = 'Texto selecionado. Use o atalho de copiar do seu teclado.';
          setCopyLabel(button, 'Selecionado: Ctrl+C');
        } else {
          message = 'Não foi possível copiar. Selecione o código manualmente.';
          setCopyLabel(button, 'Selecione o código');
        }
      }
      feedback.textContent = '';
      requestAnimationFrame(() => { feedback.textContent = message; });
      copyTimers.set(button, setTimeout(() => {
        if (button.isConnected) setCopyLabel(button, original);
      }, 2400));
    }

    function prepareCode(page) {
      const copyTargets = new Set([...article.querySelectorAll('[data-copy-target]')].map(button => button.dataset.copyTarget));
      article.querySelectorAll('pre').forEach((pre, index) => {
        const code = pre.querySelector('code');
        if (!code) return;
        if (code.classList.contains('language-php')) highlightPhp(code);
        if (!code.id) code.id = `example-${page.id}-${index}`;
        if (copyTargets.has(code.id)) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'copy';
        button.dataset.copyTarget = code.id;
        button.setAttribute('aria-label', 'Copiar exemplo de código');
        button.textContent = 'Copiar';
        pre.append(button);
        copyTargets.add(code.id);
      });
      const tabs = [...article.querySelectorAll('[data-code-tab]')];
      if (!tabs.length) return;
      const select = (tab, focus = false) => {
        tabs.forEach(button => {
          const selected = button === tab;
          button.setAttribute('role', 'tab');
          button.setAttribute('aria-selected', String(selected));
          button.tabIndex = selected ? 0 : -1;
          button.id ||= 'tab-' + button.dataset.codeTab;
          const panel = $('panel-' + button.dataset.codeTab) || article.querySelector(`[data-code-panel="${button.dataset.codeTab}"]`);
          if (panel) {
            button.setAttribute('aria-controls', panel.id);
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', button.id);
            panel.hidden = !selected;
          }
        });
        if (focus) tab.focus();
      };
      tabs[0].parentElement?.setAttribute('role', 'tablist');
      tabs[0].parentElement?.setAttribute('aria-label', 'Exemplos em PHP');
      tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => select(tab));
        tab.addEventListener('keydown', event => {
          const next = event.key === 'ArrowRight' ? (index + 1) % tabs.length : event.key === 'ArrowLeft' ? (index + tabs.length - 1) % tabs.length : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : null;
          if (next !== null) { event.preventDefault(); select(tabs[next], true); }
        });
      });
      select(tabs.find(tab => tab.getAttribute('aria-selected') === 'true') || tabs[0]);
    }

    function prepareTables() {
      article.querySelectorAll('table').forEach(table => {
        if (table.parentElement?.classList.contains('table-wrap')) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'table-wrap';
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute('aria-label', 'Tabela com rolagem horizontal');
        table.before(wrapper);
        wrapper.append(table);
      });
    }

    function prepareApiFilter() {
      const filter = $('api-filter');
      const entries = [...article.querySelectorAll('[data-api-entry]')];
      if (!filter || !entries.length) return;
      const labels = entries.map(entry => fold(entry.textContent));
      filter.addEventListener('input', () => {
        const query = fold(filter.value.trim());
        let count = 0;
        entries.forEach((entry, index) => { entry.hidden = !labels[index].includes(query); if (!entry.hidden) count++; });
        if ($('api-count')) $('api-count').textContent = `${count} ${count === 1 ? 'símbolo' : 'símbolos'}`;
        if ($('api-empty')) $('api-empty').hidden = count !== 0;
      });
    }

    function prepareOutline() {
      outlineObserver?.disconnect();
      const toc = $('toc');
      const links = toc ? [...toc.querySelectorAll('a[href]')] : [];
      const outline = $('page-outline');
      if (outline) outline.hidden = !links.length;
      if (!links.length || !('IntersectionObserver' in window)) return;
      const targets = links.map(link => $(decode(link.hash.slice(link.hash.indexOf('/') + 1)))).filter(element => element && article.contains(element));
      const visible = new Set();
      outlineObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => { if (entry.isIntersecting) visible.add(entry.target); else visible.delete(entry.target); });
        const first = [...visible].sort((a, b) => a.getBoundingClientRect().top - b.getBoundingClientRect().top)[0];
        if (!first) return;
        links.forEach(link => {
          const current = decode(link.hash.slice(link.hash.indexOf('/') + 1)) === first.id;
          link.classList.toggle('active', current);
          if (current) link.setAttribute('aria-current', 'location'); else link.removeAttribute('aria-current');
        });
      }, { rootMargin: '-100px 0px -55% 0px', threshold: 0 });
      targets.forEach(target => outlineObserver.observe(target));
    }

    function scrollToAnchor(anchor, focus) {
      requestAnimationFrame(() => {
        const heading = anchor ? $(anchor) : null;
        if (heading && article.contains(heading)) {
          heading.scrollIntoView({ block: 'start' });
          if (focus) {
            if (!heading.hasAttribute('tabindex')) {
              heading.tabIndex = -1;
              heading.addEventListener('blur', () => heading.removeAttribute('tabindex'), { once: true });
            }
            heading.focus({ preventScroll: true });
          }
        } else { window.scrollTo(0, 0); if (focus) focusContent(); }
      });
    }

    function route(initial = false) {
      const fragment = location.hash.slice(1);
      if (fragment === 'content' && active) { focusContent(true); return; }
      const slash = fragment.indexOf('/');
      const id = decode(slash < 0 ? fragment : fragment.slice(0, slash));
      const known = byId.get(id);
      const anchor = !known || slash < 0 ? '' : decode(fragment.slice(slash + 1));
      const page = known || home;
      const changed = active?.id !== page.id;
      active = page;
      closeSearch(false);
      setSidebar(false);
      document.body.classList.toggle('home', page.id === home.id);
      if (changed) {
        article.classList.toggle('document-body', page.id !== home.id);
        article.innerHTML = page.body;
        if ($('toc')) { $('toc').innerHTML = page.toc || ''; rewriteLinks($('toc'), page, true); }
        if ($('group')) $('group').textContent = page.group;
        if ($('current-page')) $('current-page').textContent = page.title;
        if ($('reading-time')) {
          const words = (pageText.get(page.id) || '').split(/\s+/).filter(Boolean).length;
          $('reading-time').textContent = Math.max(1, Math.ceil(words / 220)) + ' min de leitura';
        }
        const source = $('source');
        if (source) { source.hidden = !page.source; if (page.source) source.href = sourceHref(page.source); else source.removeAttribute('href'); }
        document.title = page.title + ' · MVO WhatsApp';
        document.querySelectorAll('.header-link').forEach(link => {
          link.classList.toggle('selected', link.hash === (page.id === 'api' ? '#api' : '#inicio'));
        });
        document.querySelectorAll('[data-page]').forEach(link => {
          const current = link.dataset.page === page.id;
          link.classList.toggle('active', current);
          if (current) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
        });
        rewriteLinks(article, page);
        bindLogo();
        prepareCode(page);
        prepareTables();
        prepareApiFilter();
        const index = documents.indexOf(page);
        if ($('paging')) $('paging').innerHTML =
          (index ? `<a class="paging-previous" href="${routeHref(documents[index - 1])}"><span>Anterior</span><strong>${escape(documents[index - 1].title)}</strong></a>` : '<span></span>') +
          (index < documents.length - 1 ? `<a class="paging-next" href="${routeHref(documents[index + 1])}"><span>Próximo</span><strong>${escape(documents[index + 1].title)}</strong></a>` : '');
        prepareOutline();
      }
      revealActiveNavigation();
      scrollToAnchor(anchor, !initial || fragment === 'content');
    }

    function renderSearch() {
      if (!search || !results) return;
      const query = fold(search.value.trim()).slice(0, 180);
      const terms = query.split(/\s+/).filter(Boolean);
      let matches = searchable.filter(item => terms.every(term => item.normalized.includes(term)));
      if (terms.length) matches = matches.map(item => ({ ...item, score: terms.reduce((score, term) => score + (item.title === term ? 8 : item.title.startsWith(term) ? 5 : item.title.includes(term) ? 3 : 0), 0) })).sort((a, b) => b.score - a.score);
      matches = matches.slice(0, 12);
      results.innerHTML = matches.length ? matches.map(({ page, text }, index) => {
        const position = query ? fold(text).indexOf(terms[0]) : 0;
        const start = Math.max(0, position - 50);
        const snippet = text.slice(start, start + 155);
        return `<a class="results-item" id="search-result-${index}" href="${routeHref(page)}"><span class="result-title">${escape(page.title)}</span><small>${escape(page.group)}</small><p>${start ? '…' : ''}${escape(snippet)}${start + 155 < text.length ? '…' : ''}</p></a>`;
      }).join('') : '<p class="no-results">Nenhum resultado. Tente “conexão”, “mídia” ou “sendMessage”.</p>';
      searchLinks = [...results.querySelectorAll('a.results-item')];
      const count = $('search-count');
      if (count) count.textContent = query ? `${matches.length} ${matches.length === 1 ? 'página encontrada' : 'páginas encontradas'}` : 'Explore a documentação';
      searchLinks.forEach(link => link.addEventListener('focus', () => searchLinks.forEach(item => item.classList.toggle('active', item === link))));
    }

    function openSearch() {
      if (!dialog || !search) return;
      if (!searchIsOpen) restoreSearchFocus = document.activeElement;
      setSidebar(false);
      searchIsOpen = true;
      document.body.classList.add('search-open');
      $('search-open')?.setAttribute('aria-expanded', 'true');
      dialog.setAttribute('aria-modal', 'true');
      try { if (!dialog.open && typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', ''); }
      catch { dialog.setAttribute('open', ''); }
      renderSearch();
      requestAnimationFrame(() => { search.focus(); search.select(); });
    }

    function closeSearch(restore = true) {
      if (!searchIsOpen) return;
      searchIsOpen = false;
      document.body.classList.remove('search-open');
      $('search-open')?.setAttribute('aria-expanded', 'false');
      if (dialog) { if (typeof dialog.close === 'function' && dialog.open) dialog.close(); else dialog.removeAttribute('open'); }
      if (restore && restoreSearchFocus?.isConnected) restoreSearchFocus.focus({ preventScroll: true });
      restoreSearchFocus = null;
    }

    function trapTab(event, elements) {
      const focusable = elements.filter(element => !element.hidden && !element.disabled && element.getClientRects().length);
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && (document.activeElement === first || !focusable.includes(document.activeElement))) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && (document.activeElement === last || !focusable.includes(document.activeElement))) { event.preventDefault(); first.focus(); }
    }

    function setTheme(light) {
      document.body.classList.toggle('light', light);
      const button = $('theme');
      if (button) {
        button.setAttribute('aria-pressed', String(light));
        button.setAttribute('aria-label', light ? 'Ativar tema escuro' : 'Ativar tema claro');
        button.title = light ? 'Ativar tema escuro' : 'Ativar tema claro';
      }
      if ($('theme-label')) $('theme-label').textContent = light ? 'Tema claro' : 'Tema escuro';
    }

    document.addEventListener('click', event => {
      const element = event.target instanceof Element ? event.target : null;
      const copy = element?.closest('[data-copy-target]');
      if (copy) { event.preventDefault(); copyCode(copy); return; }
      const link = element?.closest('a[href]');
      if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target === '_blank') return;
      const href = link.getAttribute('href');
      if (href === '#content') { event.preventDefault(); closeSearch(false); setSidebar(false); focusContent(true); return; }
      if (!href?.startsWith('#')) return;
      closeSearch(false);
      setSidebar(false);
      if (href === location.hash) { event.preventDefault(); route(); }
    });
    document.addEventListener('keydown', event => {
      if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k') { event.preventDefault(); openSearch(); return; }
      if (event.key === 'Escape') {
        if (searchIsOpen) { event.preventDefault(); closeSearch(); }
        else if (sidebarIsOpen) { event.preventDefault(); setSidebar(false, true); }
      }
      if (searchIsOpen) {
        if (event.key === 'Tab') trapTab(event, [...dialog.querySelectorAll('button, input, a[href], [tabindex="0"]')]);
        const index = searchLinks.indexOf(document.activeElement);
        if ((document.activeElement === search || index >= 0) && searchLinks.length) {
          if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); const next = event.key === 'ArrowDown' ? (index + 1) % searchLinks.length : (index < 0 ? searchLinks.length - 1 : (index + searchLinks.length - 1) % searchLinks.length); searchLinks[next].focus(); }
          else if (event.key === 'Enter' && document.activeElement === search) { event.preventDefault(); searchLinks[0].click(); }
        }
      } else if (sidebarIsOpen && event.key === 'Tab') trapTab(event, [menu, ...sidebar.querySelectorAll('summary, a[href], button')].filter(Boolean));
    });
    menu?.addEventListener('click', () => setSidebar(!sidebarIsOpen, sidebarIsOpen));
    overlay?.addEventListener('click', () => setSidebar(false, true));
    $('search-open')?.addEventListener('click', openSearch);
    $('search-close')?.addEventListener('click', () => closeSearch());
    search?.addEventListener('input', renderSearch);
    dialog?.addEventListener('cancel', event => { event.preventDefault(); closeSearch(); });
    dialog?.addEventListener('close', () => { if (searchIsOpen && !dialog.open) closeSearch(); });
    dialog?.addEventListener('click', event => {
      if (event.target !== dialog) return;
      const bounds = dialog.getBoundingClientRect();
      if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) closeSearch();
    });
    $('theme')?.addEventListener('click', () => {
      const light = !document.body.classList.contains('light');
      setTheme(light);
      try { localStorage.setItem('mvo-docs-theme-v3', light ? 'light' : 'dark'); } catch { /* Private/offline storage may be unavailable. */ }
    });
    window.addEventListener('hashchange', () => route());
    window.addEventListener('resize', () => {
      if (resizeFrame !== null) return;
      resizeFrame = requestAnimationFrame(() => {
        resizeFrame = null;
        syncHeaderHeight();
        if (sidebarIsOpen && menu && getComputedStyle(menu).display === 'none') setSidebar(false);
      });
    });
    if ('ResizeObserver' in window && $('site-header')) new ResizeObserver(syncHeaderHeight).observe($('site-header'));
    navigation();
    bindLogo();
    syncHeaderHeight();
    let saved;
    try { saved = localStorage.getItem('mvo-docs-theme-v3'); } catch { /* The light theme is available without storage. */ }
    setTheme(saved !== 'dark');
    route(true);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
