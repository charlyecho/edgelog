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
