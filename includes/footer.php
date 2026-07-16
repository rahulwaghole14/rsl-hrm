        </main>
        <footer style="margin-top: 3rem; text-align: center; color: var(--text-muted); font-size: 0.8rem; padding-bottom: 2rem;">
            &copy; 2026 RSL Calendar System. Built for efficiency.
        </footer>
    </div> <!-- .scrollable-content -->
</div> <!-- .main-container -->
</div> <!-- .app-layout -->

<style>
    /* Search highlight styles */
    .day-cell.search-match {
        outline: 3px solid var(--primary-color) !important;
        outline-offset: -3px;
        z-index: 10 !important;
        animation: searchPulse 1.5s ease-in-out infinite;
    }

    .day-cell.search-dimmed {
        opacity: 0.3;
    }

    .event-tag.search-highlight,
    .holiday-tag.search-highlight,
    .birthday-tag.search-highlight,
    .anniversary-tag.search-highlight,
    .leave-tag.search-highlight {
        background: #fef08a !important;
        color: #1e293b !important;
        font-weight: 700 !important;
        box-shadow: 0 0 8px rgba(250, 204, 21, 0.6);
    }

    @keyframes searchPulse {
        0%, 100% { outline-color: var(--primary-color); }
        50% { outline-color: #a5b4fc; }
    }

    /* Search dropdown */
    .search-dropdown {
        position: absolute;
        top: calc(100% + 0.5rem);
        left: 0;
        right: 0;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 1rem;
        box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        max-height: 400px;
        overflow-y: auto;
        z-index: 999;
        display: none;
    }

    .search-dropdown.visible { display: block; }

    .search-result-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 0.85rem 1.25rem;
        cursor: pointer;
        transition: background 0.15s;
        text-decoration: none;
        color: var(--text-main);
        border-bottom: 1px solid var(--border-color);
    }

    .search-result-item:last-child { border-bottom: none; }
    .search-result-item:hover { background: #f1f5f9; }

    .search-result-item:first-child { border-radius: 1rem 1rem 0 0; }
    .search-result-item:last-child { border-radius: 0 0 1rem 1rem; }

    .result-badge {
        padding: 0.2rem 0.6rem;
        border-radius: 0.4rem;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        flex-shrink: 0;
    }

    .result-badge.holiday { background: #fee2e2; color: #ef4444; }
    .result-badge.event { background: #fef3c7; color: #d97706; }
    .result-badge.half_day { background: #fef9c3; color: #ca8a04; }
    .result-badge.birthday { background: #ede9fe; color: #7c3aed; }
    .result-badge.anniversary { background: #ffedd5; color: #c2410c; }
    .result-badge.working { background: #dcfce7; color: #16a34a; }

    .result-title { font-weight: 600; font-size: 0.9rem; }
    .result-date { font-size: 0.8rem; color: var(--text-muted); }

    .search-no-results {
        padding: 1.5rem;
        text-align: center;
        color: var(--text-muted);
        font-size: 0.9rem;
    }

    .search-loading {
        padding: 1.5rem;
        text-align: center;
        color: var(--text-muted);
    }

    .search-results-badge {
        position: absolute;
        right: 1rem;
        top: 50%;
        transform: translateY(-50%);
        background: var(--primary-color);
        color: white;
        padding: 0.15rem 0.6rem;
        border-radius: 1rem;
        font-size: 0.75rem;
        font-weight: 700;
        pointer-events: none;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('calendarSearch');
    if (!searchInput) return;

    const headerCenter = searchInput.parentElement;
    headerCenter.style.position = 'relative';

    // Create dropdown
    const dropdown = document.createElement('div');
    dropdown.className = 'search-dropdown';
    headerCenter.appendChild(dropdown);

    // Create badge
    const badge = document.createElement('span');
    badge.className = 'search-results-badge';
    badge.style.display = 'none';
    headerCenter.appendChild(badge);

    let debounceTimer;
    let currentQuery = '';

    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        currentQuery = query;

        clearTimeout(debounceTimer);

        if (query.length < 2) {
            dropdown.classList.remove('visible');
            badge.style.display = 'none';
            clearHighlights();
            return;
        }

        badge.style.display = 'none';
        dropdown.innerHTML = '<div class="search-loading">Searching all months...</div>';
        dropdown.classList.add('visible');

        debounceTimer = setTimeout(() => fetchResults(query), 250);
    });

    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            this.value = '';
            currentQuery = '';
            dropdown.classList.remove('visible');
            badge.style.display = 'none';
            clearHighlights();
            this.blur();
        }
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!headerCenter.contains(e.target)) {
            dropdown.classList.remove('visible');
        }
    });

    // Re-show dropdown on focus if there's a query
    searchInput.addEventListener('focus', function() {
        if (this.value.trim().length >= 2 && dropdown.innerHTML) {
            dropdown.classList.add('visible');
        }
    });

    function fetchResults(query) {
        fetch('search_events.php?q=' + encodeURIComponent(query))
            .then(res => res.json())
            .then(results => {
                // Check if query hasn't changed while we were fetching
                if (query !== currentQuery) return;

                if (results.length === 0) {
                    dropdown.innerHTML = '<div class="search-no-results">No events or holidays found for "<strong>' + escapeHtml(query) + '</strong>"</div>';
                    badge.textContent = 'No results';
                    badge.style.display = 'inline-block';
                    badge.style.background = '#ef4444';
                    clearHighlights();
                    return;
                }

                badge.textContent = results.length + ' found';
                badge.style.display = 'inline-block';
                badge.style.background = 'var(--primary-color)';

                let html = '';
                results.forEach(item => {
                    const badgeClass = item.type;
                    const label = item.type === 'half_day' ? 'Half Day' : item.type.charAt(0).toUpperCase() + item.type.slice(1);
                    
                    html += '<a href="index.php?month=' + item.month + '&year=2026&view=month" class="search-result-item">';
                    html += '<span class="result-badge ' + badgeClass + '">' + label + '</span>';
                    html += '<div><div class="result-title">' + escapeHtml(item.title) + '</div>';
                    html += '<div class="result-date">' + item.date_display + '</div></div>';
                    html += '</a>';
                });

                dropdown.innerHTML = html;

                // Also highlight on current page if results match
                highlightOnPage(query);
            })
            .catch(() => {
                dropdown.innerHTML = '<div class="search-no-results">Search failed. Please try again.</div>';
            });
    }

    function highlightOnPage(query) {
        clearHighlights();
        const dayCells = document.querySelectorAll('.day-cell:not(.other-month)');
        const allTags = document.querySelectorAll('.event-tag, .holiday-tag, .birthday-tag, .anniversary-tag, .leave-tag');
        const lowerQuery = query.toLowerCase();
        const matchedCells = new Set();

        allTags.forEach(tag => {
            if (tag.textContent.toLowerCase().includes(lowerQuery)) {
                tag.classList.add('search-highlight');
                const parentCell = tag.closest('.day-cell');
                if (parentCell && !parentCell.classList.contains('other-month')) {
                    matchedCells.add(parentCell);
                }
            }
        });

        dayCells.forEach(cell => {
            if (matchedCells.has(cell)) {
                cell.classList.add('search-match');
            } else {
                cell.classList.add('search-dimmed');
            }
        });
    }

    function clearHighlights() {
        document.querySelectorAll('.day-cell').forEach(c => c.classList.remove('search-match', 'search-dimmed'));
        document.querySelectorAll('.search-highlight').forEach(t => t.classList.remove('search-highlight'));
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Auto-submit search forms in the application on user input (debounced)
    const formsWithSearch = document.querySelectorAll('form');
    formsWithSearch.forEach(form => {
        const searchInput = form.querySelector('input[name="search"]');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    form.submit();
                }, 500); // 500ms debounce
            });

            // Restore focus and cursor position to end of input if search value exists
            if (searchInput.value) {
                searchInput.focus();
                const length = searchInput.value.length;
                searchInput.setSelectionRange(length, length);
            }
        }
    });
});
</script>


</body>
</html>