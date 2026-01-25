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

    // columns
    const columnsWrap = document.createElement('div');
    columnsWrap.className = 'bt__settings--columns';
    const h3 = document.createElement('h3');
    h3.textContent = 'Sichtbare Spalten';
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

    // Meta flush/reset
    const clearMeta = document.createElement('p');
    clearMeta.className = 'bt__settings--clear-meta';
    const clearLink = document.createElement('a');
    clearLink.href = '?clear_usermeta';
    clearLink.textContent = 'Einstellungen zurücksetzen';
    clearMeta.appendChild(clearLink);
    container.appendChild(clearMeta);

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
    const fields = document.querySelectorAll('.bt__settings input');
    const columns = document.querySelectorAll('.bt__settings--columns input');
    const newSettings = {
      'per_page': fields[0].value, // fields[0] is per_page now (number input)
      'orderby': this.settings.orderby, // Keep existing values
      'order': this.settings.order,     // Keep existing values
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

    // Save and update
    // We can call API directly or use update() but update() reads from DOM.
    // DOM now lacks sort fields, so update() uses this.settings.orderby/order.
    // However, update() relies on fields[0] being per_page. 
    // If we call update(), it will read per_page from DOM and use current this.settings.orderby/order.
    // This is correct.
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
       // Reload data to ensure rows match the new column order
       //this.updateTableData(this.settings);
    });
  }
}