import {ROOT_ID} from "../constants.js";
import {API} from "../classes/API.js";

export class Pagination {
  constructor(container, opts = {}) {
    this.container = container;
    this.current = parseInt(opts.current) || 1;
    this.total = parseInt(opts.total) || 0;
    this.per_page = parseInt(opts.per_page) || 10;
    this.onPageChange = opts.onPageChange || function(){}; // function(newPage) -> should fetch rows
    this.onRows = opts.onRows || function(){}; // function(rows)
    this.ajax = new API(lbwpBetterTables.ajax_url);
    this.render();
  }

  render() {
    this.container.innerHTML = '';
    this.el = document.createElement('div');
    this.el.className = 'bt__pagination';

    // prev button
    this.prevBtn = document.createElement('button');
    this.prevBtn.className = 'prev-page button';
    this.prevBtn.innerHTML = '<span aria-hidden="true">‹</span>';
    this.prevBtn.addEventListener('click', () => this.goToPage('prev'));
    this.el.appendChild(this.prevBtn);

    // pages container
    this.pagesDiv = document.createElement('div');
    this.pagesDiv.className = 'pages';
    this.input = document.createElement('input');
    this.input.value = this.current;
    this.input.addEventListener('change', (e) => this.goToPage(e.target.value));
    this.pagesDiv.appendChild(this.input);

    const pagesCount = Math.ceil(this.total / this.per_page) || 1;
    const span = document.createElement('span');
    span.textContent = ' von ' + pagesCount;
    this.pagesDiv.appendChild(span);

    this.el.appendChild(this.pagesDiv);

    // next button
    this.nextBtn = document.createElement('button');
    this.nextBtn.className = 'next-page button';
    this.nextBtn.innerHTML = '<span aria-hidden="true">›</span>';
    this.nextBtn.addEventListener('click', () => this.goToPage('next'));
    this.el.appendChild(this.nextBtn);

    this.container.appendChild(this.el);
    this.updateButtons();
  }

  updateButtons() {
    const pages = Math.ceil(this.total / this.per_page) || 1;
    this.prevBtn.disabled = this.current <= 1;
    this.nextBtn.disabled = this.current >= pages;
    if (this.input) this.input.value = this.current;
  }

  goToPage(page) {
    if (page === 'next') {
      this.current = Number(this.current) + 1;
    } else if (page === 'prev') {
      this.current = Number(this.current) - 1;
    } else {
      this.current = Number(page) || 1;
    }

    this.updateButtons();
    // gather filter inputs
    let ajaxArgs = {
      per_page: this.per_page,
      page: this.current,
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

    // fetch rows and emit
    const tableEl = document.querySelector(ROOT_ID + ' table');
    if (tableEl) tableEl.classList.add('loading');
    this.ajax.get('users', ajaxArgs).then((response) => response.json()).then((data) => {
      this.total = data.total;
      this.onRows(data.rows);
      this.onPageChange(this.current);
      const tbl = document.querySelector(ROOT_ID + ' table');
      if (tbl) tbl.classList.remove('loading');
      this.render();
    }).catch((err) => {
      console.error('Pagination fetch error', err);
    });
  }

  setTotal(total) {
    this.total = total;
    this.render();
  }

  setCurrent(cur) {
    this.current = cur;
    this.updateButtons();
  }
}
