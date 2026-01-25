import { ROOT_ID } from "./constants.js?v=2";
import { API } from "./classes/API.js?v=2";
import { Settings } from "./classes/Settings.js?v=2";
import { Table } from "./components/Table.js?v=2";

export class Main {

  /**
   * Prepare class variables and initialize app
   */
  constructor() {
    this.root = document.querySelector(ROOT_ID);

    if (!this.root) {
      throw new Error('Root element not found: ' + ROOT_ID);
    }

    this.ajax = new API(lbwpBetterTables.ajax_url);
    this.settings = new Settings(this.updateTableData.bind(this));
    this.table = null; // will hold Table instance

    this.init();
  }

  /**
   * Initialize the app by creating settings and table containers, loading settings, and fetching initial data
   */
  init() {
    // Create settings container
    this.settingsContainer = document.createElement('div');
    this.settingsContainer.className = 'bt__settings-container collapsed';
    const h2 = document.createElement('h2');
    h2.textContent = 'Einstellungen';
    h2.addEventListener('click', this.toggleAccordion.bind(this));
    this.settingsContainer.appendChild(h2);

    // Create placeholder for settings fields
    this.settingsFieldsHolder = document.createElement('div');
    this.settingsContainer.appendChild(this.settingsFieldsHolder);

    // Create placeholder for table
    this.tableHolder = document.createElement('div');
    this.tableHolder.className = 'bt__table--loading';
    this.tableHolder.textContent = 'Tabelle wird geladen...';

    // Append to root
    this.root.appendChild(this.settingsContainer);
    this.root.appendChild(this.tableHolder);

    // Load settings then initial data
    this.settings.setup().then(() => {
      const fieldsEl = this.settings.displayFields();
      this.settingsFieldsHolder.innerHTML = '';
      this.settingsFieldsHolder.appendChild(fieldsEl);
    }).then(() => {
      // fetch initial data
      return this.ajax.get('users', this.settings.settings).then((response) => response.json());
    }).then((initialData) => {
      this.renderTable(initialData);
    }).catch((err) => {
      console.error('Initialization error', err);
      this.tableHolder.textContent = 'Fehler beim Laden der Daten.';
    });
  }

  /**
   * Toggle the accordion state of the settings container
   */
  toggleAccordion() {
    this.settingsContainer.classList.toggle('collapsed');
  }

  /**
   * Update the table data based on new settings
   * @param newSettings
   */
  updateTableData(newSettings) {
    if (this.table) {
      const searchParams = this.table.getSearchParams();
      newSettings = { ...newSettings, ...searchParams };
    }
    
    this.ajax.get('users', newSettings).then((response) => {
      response.json().then((data) => {
        if (this.table) {
          this.table.updateData(data, newSettings);
        } else {
          this.renderTable(data, newSettings);
        }
        const tableEl = this.root.querySelector('table');
        if (tableEl) tableEl.classList.remove('loading');
      });
    });
  }

  /**
   * Render the table with given data and settings
   * @param data
   * @param settings
   */
  renderTable(data, settings = this.settings.settings) {
    this.tableHolder.innerHTML = '';
    this.table = new Table(this.tableHolder, data, this.settings);
  }
}

// Initialize the Main app when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => new Main());
} else {
  new Main();
}

