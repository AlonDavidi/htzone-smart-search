// Search UI: debounced search-as-you-type, URL sync (?q=), cancellation of stale
// requests, client-side cache, and explicit loading / empty / error states.
(() => {
  'use strict';

  const DEBOUNCE_MS = 300;
  const MIN_CHARS = 2;
  const SKELETON_COUNT = 8;

  const form = document.getElementById('search-form');
  const input = document.getElementById('q');
  const clearBtn = document.getElementById('clear');
  const title = document.getElementById('results-title');
  const statusEl = document.getElementById('status');
  const grid = document.getElementById('grid');
  const cardTpl = document.getElementById('card-template');

  const cache = new Map();   // query -> response JSON
  let controller = null;     // AbortController of the in-flight request
  let debounceTimer = null;
  let lastQuery = '';

  // ---- Rendering -------------------------------------------------------

  function clearResults() {
    title.textContent = '';
    statusEl.replaceChildren();
    grid.replaceChildren();
  }

  function renderLoading() {
    statusEl.replaceChildren();
    grid.setAttribute('aria-busy', 'true');
    grid.replaceChildren(...Array.from({ length: SKELETON_COUNT }, () => {
      const li = document.createElement('li');
      li.className = 'card skeleton';
      li.setAttribute('aria-hidden', 'true');
      li.innerHTML = '<div class="card-media"></div><div class="card-body"><div class="line"></div><div class="line short"></div></div>';
      return li;
    }));
  }

  function renderResults(query, data) {
    grid.removeAttribute('aria-busy');
    statusEl.replaceChildren();

    if (data.results.length === 0) {
      title.textContent = '';
      grid.replaceChildren();
      renderState('empty', 'לא מצאנו מוצרים מתאימים',
        'נסו לנסח אחרת, להשתמש במילה כללית יותר, או לתאר למה המוצר צריך לשמש.',
        ['שואב אבק', 'אוזניות', 'מתנה לחובב ריצה']);
      return;
    }

    const count = data.results.length;
    // Quotes stay outside <bdi>, so an English query inside Hebrew text keeps them in place.
    title.replaceChildren(
      count === 1 ? 'מצאנו מוצר אחד עבור "' : `מצאנו ${count} מוצרים עבור "`,
      Object.assign(document.createElement('bdi'), { textContent: query }),
      '"',
    );
    grid.replaceChildren(...data.results.map(renderCard));
  }

  function renderCard(p) {
    const node = cardTpl.content.firstElementChild.cloneNode(true);
    const img = node.querySelector('img');
    img.src = p.image_url;
    img.alt = p.name;
    img.addEventListener('error', () => { img.remove(); }, { once: true }); // keep the grey box
    node.querySelector('.card-name bdi').textContent = p.name;
    node.querySelector('.card-brand').textContent = p.brand ?? '';
    node.querySelector('.card-sub').textContent = p.subcategory;
    return node;
  }

  function renderState(kind, heading, text, suggestions = [], retry = false) {
    const box = document.createElement('div');
    box.className = `state ${kind}`;
    box.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    box.append(
      Object.assign(document.createElement('h3'), { textContent: heading }),
      Object.assign(document.createElement('p'), { textContent: text }),
    );
    for (const s of suggestions) {
      box.append(Object.assign(document.createElement('button'), { type: 'button', className: 'chip', textContent: s }));
    }
    if (retry) {
      const b = Object.assign(document.createElement('button'), { type: 'button', className: 'retry', textContent: 'לחפש שוב' });
      b.addEventListener('click', () => search(lastQuery, { force: true }));
      box.append(b);
    }
    statusEl.replaceChildren(box);
  }

  // ---- Search ------------------------------------------------------------

  async function search(raw, { force = false, pushUrl = true } = {}) {
    const query = raw.trim();
    lastQuery = query;
    document.body.classList.toggle('has-query', query.length >= MIN_CHARS);
    if (pushUrl) syncUrl(query);

    if (query.length < MIN_CHARS) {
      controller?.abort();
      clearResults();
      return;
    }

    if (!force && cache.has(query)) {
      renderResults(query, cache.get(query));
      return;
    }

    controller?.abort();                       // a newer query wins over a slower older one
    controller = new AbortController();
    renderLoading();

    try {
      const res = await fetch(`/api/search?q=${encodeURIComponent(query)}`, { signal: controller.signal });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || `HTTP ${res.status}`);
      cache.set(query, data);
      if (query === lastQuery) renderResults(query, data);
    } catch (err) {
      if (err.name === 'AbortError') return;
      grid.removeAttribute('aria-busy');
      grid.replaceChildren();
      title.textContent = '';
      renderState('error', 'החיפוש לא הצליח',
        navigator.onLine ? 'השרת לא הגיב כרגע. אפשר לנסות שוב בעוד רגע.' : 'נראה שאין חיבור לאינטרנט. בדקו את החיבור ונסו שוב.',
        [], true);
    }
  }

  function syncUrl(query) {
    const url = new URL(location.href);
    if (query) url.searchParams.set('q', query); else url.searchParams.delete('q');
    if (url.href !== location.href) history.replaceState(null, '', url);
  }

  function setQuery(value) {
    input.value = value;
    clearBtn.hidden = value === '';
  }

  // ---- Events ------------------------------------------------------------

  input.addEventListener('input', () => {
    clearBtn.hidden = input.value === '';
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => search(input.value), DEBOUNCE_MS);
  });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    clearTimeout(debounceTimer);
    search(input.value);
  });

  clearBtn.addEventListener('click', () => {
    setQuery('');
    search('');
    input.focus();
  });

  // Example chips (hero and empty state) run their text as a search.
  document.addEventListener('click', (e) => {
    const chip = e.target.closest('.chip');
    if (!chip) return;
    setQuery(chip.textContent);
    search(chip.textContent);
  });

  // Shared links: /?q=אייפון opens with results.
  const initial = new URL(location.href).searchParams.get('q') ?? '';
  if (initial) {
    setQuery(initial);
    search(initial, { pushUrl: false });
  }
})();
