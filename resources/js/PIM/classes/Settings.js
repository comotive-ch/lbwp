// Avoid importing Main to prevent circular dependency
import { API } from "./API.js?v=2";
import { ROOT_ID } from "../constants.js?v=2";

export class Settings {
  constructor(updateTableData) {
    this.updateTableData = updateTableData;
    this.updateFields = this.updateFields.bind(this);
    this.api = new API(lbwpBetterTables.ajax_url);
    this.settings = {};
    this.waitToUpdate = null;
  }

  /**
   * Fetch user settings from the backend
   * @returns {Promise<* | {} | {}>}
   */
  setup() {
    return this.api.post('get_users_settings', {}).then((response) => response.json())
      .then((data) => {
        this.settings = data;
        return this.settings;
      });
  }

  /**
   * Render the settings fields UI
   * @returns {*}
   */
  displayFields() {
    // return a DOM element containing the settings UI
    const container = document.createElement('div');
    container.className = 'bt__settings';

    // per_page
    const itemPerPage = document.createElement('div');
    itemPerPage.className = 'bt__settings--item';
    const labelPer = document.createElement('label');
    labelPer.htmlFor = 'per_page';
    labelPer.textContent = 'Einträge pro Seite';
    const inputPer = document.createElement('input');
    inputPer.type = 'number';
    inputPer.name = 'per_page';
    inputPer.placeholder = 'Einträge pro Seite';
    inputPer.value = this.settings.per_page;
    inputPer.addEventListener('change', this.updateFields);
    itemPerPage.appendChild(labelPer);
    itemPerPage.appendChild(inputPer);
    container.appendChild(itemPerPage);

    // orderby
    const itemOrderby = document.createElement('div');
    itemOrderby.className = 'bt__settings--item';
    const labelOrderby = document.createElement('label');
    labelOrderby.htmlFor = 'orderby';
    labelOrderby.textContent = 'Sortiert nach';
    const selectOrderby = document.createElement('select');
    selectOrderby.name = 'orderby';
    selectOrderby.value = this.settings.orderby;
    selectOrderby.addEventListener('change', this.updateFields);

    Object.entries(this.settings.columns).forEach((col) => {
      const opt = document.createElement('option');
      opt.value = col[0];
      opt.name = col[0];
      opt.textContent = col[1][0];
      selectOrderby.appendChild(opt);
    });

    itemOrderby.appendChild(labelOrderby);
    itemOrderby.appendChild(selectOrderby);
    container.appendChild(itemOrderby);

    // order
    const itemOrder = document.createElement('div');
    itemOrder.className = 'bt__settings--item';
    const labelOrder = document.createElement('label');
    labelOrder.htmlFor = 'order';
    labelOrder.textContent = 'Sortierung';
    const selectOrder = document.createElement('select');
    selectOrder.name = 'order';
    selectOrder.value = this.settings.order;
    selectOrder.addEventListener('change', this.updateFields);
    const optAsc = document.createElement('option');
    optAsc.value = 'asc';
    optAsc.textContent = 'ASC';
    const optDesc = document.createElement('option');
    optDesc.value = 'desc';
    optDesc.textContent = 'DESC';
    selectOrder.appendChild(optAsc);
    selectOrder.appendChild(optDesc);

    itemOrder.appendChild(labelOrder);
    itemOrder.appendChild(selectOrder);
    container.appendChild(itemOrder);

    // columns
    const columnsWrap = document.createElement('div');
    columnsWrap.className = 'bt__settings--columns';
    const h3 = document.createElement('h3');
    h3.textContent = 'Spalten';
    columnsWrap.appendChild(h3);
    const columnsList = document.createElement('div');
    columnsList.className = 'bt__settings--columns-list';

    Object.entries(this.settings.columns).forEach((col, index) => {
      const label = document.createElement('label');
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.name = col[0];
      input.value = col[1][0];
      input.checked = col[1][1];
      input.addEventListener('change', this.updateFields);
      label.appendChild(input);
      label.appendChild(document.createTextNode(' ' + col[1][0]));
      columnsList.appendChild(label);
    });

    columnsWrap.appendChild(columnsList);
    container.appendChild(columnsWrap);

    return container;
  }

  updateFields() {
    clearTimeout(this.waitToUpdate);
    const table = document.querySelector(ROOT_ID + ' table');
    if (table !== null) {
      table.classList.add('loading');
    }

    this.waitToUpdate = setTimeout(() => {
      this.update();
    }, 1000);
  }

  update() {
    const fields = document.querySelectorAll('.bt__settings input, .bt__settings select');
    const columns = document.querySelectorAll('.bt__settings--columns input');
    const newSettings = {
      'per_page': fields[0].value,
      'orderby': fields[1].value,
      'order': fields[2].value,
      'columns': {},
    };

    columns.forEach((col) => {
      newSettings.columns[col.name] = [col.value, col.checked];
    });

    this.api.post('save_users_settings', newSettings).then(() => {
      const pageInput = document.querySelector('.bt__pagination input');
      newSettings.page = pageInput ? pageInput.value : 1;

      this.updateTableData(newSettings);
      this.settings = newSettings;
    });
  }

  /**
   * Set the sort order and update the table
   * @param orderby
   * @param order
   */
  setSort(orderby, order) {
    this.settings.orderby = orderby;
    this.settings.order = order;

    // Update UI if it exists
    const orderbySelect = document.querySelector('.bt__settings select[name="orderby"]');
    const orderSelect = document.querySelector('.bt__settings select[name="order"]');
    if (orderbySelect) orderbySelect.value = orderby;
    if (orderSelect) orderSelect.value = order;

    // Save and update
    this.update();
  }

  /**
   * Update the column order in settings and save
   * @param {string[]} newOrder Array of column keys in the new order
   */
  setColumnOrder(newOrder) {
    const newColumns = {};
    // Reconstruct columns object in the new order
    newOrder.forEach((key) => {
      if (this.settings.columns[key]) {
        newColumns[key] = this.settings.columns[key];
      }
    });

    // Add any missing columns (just in case)
    Object.keys(this.settings.columns).forEach((key) => {
      if (!newColumns[key]) {
        newColumns[key] = this.settings.columns[key];
      }
    });

    this.settings.columns = newColumns;

    // We need to update the UI checkboxes order too, otherwise next save might revert it
    // But since we are likely re-rendering the whole table/settings, maybe just saving is enough.
    // However, `update()` reads from DOM. So we should probably NOT call `update()` here, 
    // but call the API directly or update the DOM first.
    // Actually, `update()` reads from DOM to *set* settings.
    // Here we *have* settings and want to save them.
    // So we should call API directly.

    this.api.post('save_users_settings', this.settings).then(() => {
      // Optional: Re-render settings UI if it's open to reflect order?
      // For now, just saving is enough.
    });
  }
}