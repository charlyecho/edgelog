/**
 * The daily chart's hover layer: a crosshair on the nearest day, and a
 * tooltip with that day's figures (or why it has none).
 */
function dailyChart(figure) {
    const points = JSON.parse(figure.dataset.points || '[]');
    const svg = figure.querySelector('svg');
    const hover = figure.querySelector('[data-hover]');
    const crosshair = figure.querySelector('[data-crosshair]');
    const tooltip = figure.querySelector('[data-tooltip]');
    const format = new Intl.NumberFormat(document.documentElement.lang || undefined);
    const words = { visitors: figure.dataset.visitors || 'Visitors', pages: figure.dataset.pages || 'Pages read' };

    if (!points.length || !svg || !hover) {
        return;
    }

    const hide = () => {
        crosshair.setAttribute('visibility', 'hidden');
        tooltip.classList.add('hidden');
    };

    const show = (event) => {
        const box = svg.getBoundingClientRect();
        const x = ((event.clientX - box.left) / box.width) * svg.viewBox.baseVal.width;
        const point = points.reduce((best, each) => (Math.abs(each.x - x) < Math.abs(best.x - x) ? each : best));

        crosshair.setAttribute('x1', point.x);
        crosshair.setAttribute('x2', point.x);
        crosshair.setAttribute('visibility', 'visible');

        tooltip.replaceChildren();
        const title = document.createElement('p');
        title.className = 'font-semibold';
        title.textContent = point.label;
        tooltip.append(title);

        if (point.visitors === null) {
            const note = document.createElement('p');
            note.className = 'text-ink-3';
            note.textContent = point.note;
            tooltip.append(note);
        } else {
            for (const key of ['visitors', 'pages']) {
                const row = document.createElement('p');
                row.className = 'flex items-center justify-between gap-4';
                row.innerHTML = `<span class="flex items-center gap-1.5"><span class="h-0.5 w-3 rounded-full bg-[var(--series-${key})]"></span></span><span class="font-semibold tabular-nums"></span>`;
                row.firstElementChild.append(words[key]);
                row.lastElementChild.textContent = format.format(point[key]);
                tooltip.append(row);
            }

            if (point.note) {
                const note = document.createElement('p');
                note.className = 'text-xs text-ink-3';
                note.textContent = point.note;
                tooltip.append(note);
            }
        }

        tooltip.classList.remove('hidden');
        const left = (point.x / svg.viewBox.baseVal.width) * box.width;
        tooltip.style.left = `${Math.min(Math.max(left + 12, 0), box.width - tooltip.offsetWidth)}px`;
    };

    hover.addEventListener('pointermove', show);
    hover.addEventListener('pointerleave', hide);
}

document.querySelectorAll('[data-daily-chart]').forEach(dailyChart);

/**
 * "See all" on a top list: show the rows past the first few, or hide them again.
 */
document.querySelectorAll('[data-top-list]').forEach((list) => {
    const button = list.querySelector('[data-see-all]');

    if (!button) {
        return;
    }

    const more = button.textContent;

    button.addEventListener('click', () => {
        const hidden = list.querySelector('[data-extra].hidden') !== null;
        list.querySelectorAll('[data-extra]').forEach((row) => row.classList.toggle('hidden', !hidden));
        button.textContent = hidden ? button.dataset.less : more;
    });
});

/**
 * The Real time tab reloads itself every few minutes, unless the reader paused it.
 */
document.querySelectorAll('[data-live-refresh]').forEach((bar) => {
    const button = bar.querySelector('[data-live-pause]');
    const state = bar.querySelector('[data-live-state]');
    const read = () => { try { return localStorage.getItem('live-paused') === '1'; } catch { return false; } };
    const write = (paused) => { try { localStorage.setItem('live-paused', paused ? '1' : '0'); } catch { /* private window */ } };
    let timer = null;

    const apply = (paused) => {
        clearTimeout(timer);
        button.textContent = paused ? button.dataset.resume : button.dataset.pause;
        state.textContent = paused ? button.dataset.paused : button.dataset.running;

        if (!paused) {
            timer = setTimeout(() => window.location.reload(), Number(bar.dataset.liveRefresh) * 1000);
        }
    };

    button.addEventListener('click', () => {
        const paused = !read();
        write(paused);
        apply(paused);
    });

    apply(read());
});

/**
 * A "Copy" button copies the text of the element its data-copy names, and
 * says so for a moment.
 */
document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        const source = document.querySelector(button.dataset.copy);

        if (!source || !navigator.clipboard) {
            return;
        }

        await navigator.clipboard.writeText(source.textContent);
        const label = button.textContent;
        button.textContent = button.dataset.copied || '✓';
        setTimeout(() => {
            button.textContent = label;
        }, 1500);
    });
});

/**
 * A [data-drawer] link opens its page in the side panel, asked for in panel
 * form; Ctrl or Cmd and a middle click still open it as a page.
 */
const drawer = document.querySelector('[data-drawer-root]');

if (drawer) {
    const body = drawer.querySelector('[data-drawer-body]');
    const page = drawer.querySelector('[data-drawer-page]');
    const close = () => {
        drawer.classList.add('hidden');
        drawer.setAttribute('aria-hidden', 'true');
        body.replaceChildren();
    };

    document.addEventListener('click', async (event) => {
        const link = event.target.closest('a[data-drawer]');

        if (link) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) {
                return;
            }

            event.preventDefault();
            const url = new URL(link.href);
            page.href = url.toString();
            url.searchParams.set('panel', '1');
            body.textContent = '…';
            drawer.classList.remove('hidden');
            drawer.setAttribute('aria-hidden', 'false');

            const response = await fetch(url, { headers: { Accept: 'text/html' } });
            body.innerHTML = response.ok ? await response.text() : `<p class="text-bad">${response.status}</p>`;

            return;
        }

        if (event.target.closest('[data-drawer-close]')) {
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !drawer.classList.contains('hidden')) {
            close();
        }
    });
}
