// Avoid importing Main to prevent circular dependency
import { API } from '../classes/API.js?v=2';
import { ROOT_ID } from '../constants.js?v=2';
import { Pagination } from './Pagination.js?v=2';

export class Table {
  constructor(container, data, settingsInstance) {
    this.container = container;
    this.data = data;
    this.settingsInstance = settingsInstance;
    this.settings = settingsInstance.settings;
    this.ajax = new API(lbwpBetterTables.ajax_url);
    this.debounceTimeout = null;
    this.curPage = 1;
    this.rows = data.rows || [];
    this.total = data.total || 0;
    this.columns = Object.keys(this.data.columns);

    this.render();
  }

  /**
   * Render the table structure including headers, input fields for filtering, and pagination controls
   */
  render() {
    this.container.innerHTML = '';
    const tableContainer = document.createElement('div');
    this.container.className = 'bt__table--container';

    this.table = document.createElement('table');
    this.table.className = 'bt__table';

    const thead = document.createElement('thead');
    const trHead = document.createElement('tr');

    this.columns.forEach((column, index) => {
      const th = document.createElement('th');
      th.draggable = index >= 2; // Make header draggable except for first two columns
      th.dataset.column = column;
      th.dataset.index = index;

      const thContent = document.createElement('div');
      thContent.className = 'bt__th-content';

      // Title & Sort
      const titleDiv = document.createElement('div');
      titleDiv.className = 'bt__th-title';
      titleDiv.textContent = this.data.columns[column];
      titleDiv.style.cursor = 'pointer';

      if (this.settings.orderby === column) {
        const arrow = document.createElement('span');
        arrow.className = 'bt__sort-arrow';
        arrow.innerHTML = this.settings.order === 'asc' ? ' &uarr;' : ' &darr;';
        titleDiv.appendChild(arrow);
      }

      titleDiv.addEventListener('click', () => {
        let newOrder = 'asc';
        if (this.settings.orderby === column && this.settings.order === 'asc') {
          newOrder = 'desc';
        }
        this.settingsInstance.setSort(column, newOrder);
      });

      thContent.appendChild(titleDiv);

      // Search Input
      const input = document.createElement('input');
      input.className = 'bt__table--input';
      input.type = 'text';
      input.name = column;
      input.placeholder = 'Search...';
      input.addEventListener('input', this.filter.bind(this));
      // Prevent drag when interacting with input
      input.addEventListener('mousedown', (e) => e.stopPropagation());
      thContent.appendChild(input);

      th.appendChild(thContent);

      // Drag Events
      th.addEventListener('dragstart', (e) => {
        e.dataTransfer.setData('text/plain', index);
        e.dataTransfer.effectAllowed = 'move';
        th.classList.add('bt__th--dragging');
      });

      th.addEventListener('dragend', () => {
        th.classList.remove('bt__th--dragging');
        document.querySelectorAll('.bt__th--drag-over').forEach(el => el.classList.remove('bt__th--drag-over'));
      });

      th.addEventListener('dragover', (e) => {
        e.preventDefault(); // Allow drop
        e.dataTransfer.dropEffect = 'move';
      });

      th.addEventListener('dragenter', (e) => {
        e.preventDefault();
        th.classList.add('bt__th--drag-over');
      });

      th.addEventListener('dragleave', () => {
        th.classList.remove('bt__th--drag-over');
      });

      th.addEventListener('drop', (e) => {
        e.preventDefault();
        th.classList.remove('bt__th--drag-over');
        const fromIndex = parseInt(e.dataTransfer.getData('text/plain'));
        let toIndex = index;

        // Restrict first two columns from being moved
        if(toIndex < 2){
          toIndex = 2;
        }

        if (fromIndex !== toIndex) {
          // Reorder columns
          const movedColumn = this.columns[fromIndex];
          this.columns.splice(fromIndex, 1);
          this.columns.splice(toIndex, 0, movedColumn);

          // Update Settings
          this.settingsInstance.setColumnOrder(this.columns);

          // Move table columns in DOM
         const rows = this.table.querySelectorAll('tr');
          rows.forEach((row) => {
            const cells = row.children;
            if (cells.length > Math.max(fromIndex, toIndex)) {
              const movedCell = cells[fromIndex];
              row.removeChild(movedCell);
              row.insertBefore(movedCell, toIndex > fromIndex ? cells[toIndex] : cells[toIndex]);
            }
          });
        }
      });

      trHead.appendChild(th);
    });

    thead.appendChild(trHead);
    this.table.appendChild(thead);

    this.tbody = document.createElement('tbody');
    this.table.appendChild(this.tbody);

    tableContainer.appendChild(this.table);
    this.container.appendChild(tableContainer);

    // Pagination holder
    this.paginationHolder = document.createElement('div');
    this.container.appendChild(this.paginationHolder);

    // render rows and pagination
    this.renderRows();
    this.pagination = new Pagination(this.paginationHolder, {
      current: this.curPage,
      total: this.total,
      per_page: this.settings.per_page,
      onPageChange: (newPage) => this.setPage(newPage),
      onRows: (rows) => this.setRows(rows)
    });
  }

  renderRows() {
    this.tbody.innerHTML = '';
    if (!this.rows || this.rows.length === 0) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = this.columns.length;
      td.textContent = 'No results found';
      tr.appendChild(td);
      this.tbody.appendChild(tr);
      return;
    }

    this.rows.forEach((row) => {
      const tr = document.createElement('tr');
      row.forEach((cell) => {
        const td = document.createElement('td');
        td.innerHTML = cell;
        tr.appendChild(td);
      });
      this.tbody.appendChild(tr);
    });
  }

  filter() {
    // Reset page to 1 when filtering
    this.curPage = 1;

    // Clear previous timeout
    if (this.debounceTimeout) clearTimeout(this.debounceTimeout);

    this.debounceTimeout = setTimeout(() => {
      const ajaxArgs = {
        per_page: this.settings.per_page,
        page: this.curPage,
        search: [],
        search_column: []
      };

      document.querySelectorAll('.bt__table--input').forEach((input) => {
        if (input.value !== '') {
          ajaxArgs.search.push(input.value);
          ajaxArgs.search_column.push(input.name);
        }
      });

      if (ajaxArgs.search.length <= 0) delete ajaxArgs.search;
      if (ajaxArgs.search_column.length <= 0) delete ajaxArgs.search_column;

      const tableEl = document.querySelector(ROOT_ID + ' table');
      if (tableEl) tableEl.classList.add('loading');

      this.ajax.get('users', ajaxArgs).then((response) => response.json()).then((data) => {
        this.rows = data.rows;
        this.total = data.total;
        this.renderRows();
        if (this.pagination) this.pagination.setTotal(this.total);
        const tbl = document.querySelector(ROOT_ID + ' table');
        if (tbl) tbl.classList.remove('loading');
      });
    }, 500);
  }

  setRows(rows) {
    this.rows = rows;
    this.renderRows();
  }

  setPage(newPage) {
    this.curPage = Number(newPage);
    // update input in pagination
    if (this.pagination) this.pagination.setCurrent(this.curPage);
  }

  updateData(data, settings = this.settings) {
    this.data = data;
    this.settings = settings;
    this.rows = data.rows || [];
    this.total = data.total || 0;

    // Update sort indicators in headers
    this.updateHeaders();

    // Re-render rows and update pagination
    this.renderRows();
    if (this.pagination) this.pagination.setTotal(this.total);
  }

  updateHeaders() {
    // Update arrows
    this.columns.forEach((column, index) => {
      // Find the th for this column
      // We assume order matches
      const th = this.table.querySelector(`thead th:nth-child(${index + 1})`);
      if (!th) return;

      const titleDiv = th.querySelector('.bt__th-title');
      if (!titleDiv) return;

      // Remove existing arrow
      const existingArrow = titleDiv.querySelector('.bt__sort-arrow');
      if (existingArrow) existingArrow.remove();

      // Add new arrow if active
      if (this.settings.orderby === column) {
        const arrow = document.createElement('span');
        arrow.className = 'bt__sort-arrow';
        arrow.innerHTML = this.settings.order === 'asc' ? ' &uarr;' : ' &darr;';
        titleDiv.appendChild(arrow);
      }
    });
  }
}
