import API from "./API";

class Settings {
  constructor(updateTableData) {
    this.updateTableData = updateTableData;
    this.updateFields = this.updateFields.bind(this);
    this.api = new API(lbwpBetterTables.ajax_url);
    this.settings = {};
  }

  setup() {
    return this.api.post('get_users_settings', {}).then((response) => response.json())
      .then((data) => {
        this.settings = data;
        return this.settings;
      });
  }

  displayFields() {
    return (
      <div className={"bt__settings"}>
        <div className={"bt__settings--item"}>
          <label htmlFor={"per_page"}>Einträge pro Seite</label>
          <input type={"number"} name={"per_page"} placeholder={"Einträge pro Seite"} defaultValue={this.settings.per_page} onChange={this.updateFields} />
        </div>
        <div className={"bt__settings--item"}>
          <label htmlFor={"orderby"}>Sortiert nach</label>
          <select name={"orderby"} defaultValue={this.settings.orderby} onChange={this.updateFields}>
            {
              Object.entries(this.settings.columns).map((col, index) => {
                return (
                  <option key={col[0] + '_' + index} name={col[0]} value={col[0]}>{col[1][0]}</option>
                );
              })
            }
          </select>
        </div>
        <div className={"bt__settings--item"}>
          <label htmlFor={"order"}>Sortierung</label>
          <select name={"order"} defaultValue={this.settings.order} onChange={this.updateFields}>
            <option key={"asc"} value={"asc"}>ASC</option>
            <option key={"desc"} value={"desc"}>DESC</option>
          </select>
        </div>
        <div className={"bt__settings--columns"}>
          <h3>Spalten</h3>
          <div className={"bt__settings--columns-list"}>
            {Object.entries(this.settings.columns).map((col, index) => (
              <label key={"label_" + index}>
                <input key={col[0] + "_" + index} type={"checkbox"} name={col[0]} value={col[0]} defaultChecked={col[1][1]} onChange={this.updateFields} /> {col[1][0]}
              </label>
            ))}
          </div>
        </div>
      </div>
    );
  }

  updateFields() {
    clearTimeout(this.waitToUpdate);
    document.querySelector('#react-root table').classList.add('loading');

    this.waitToUpdate = setTimeout(() => {
      this.update();
    }, 1000);
  }

  update() {
    let fields = document.querySelectorAll('.bt__settings input, .bt__settings select');
    let columns = document.querySelectorAll('.bt__settings--columns input');
    let newSettings = {
      'per_page': fields[0].value,
      'orderby': fields[1].value,
      'order': fields[2].value,
      'columns': {},
    };

    columns.forEach((col) => {
      newSettings.columns[col.name] = [col.value, col.checked];
    });

    this.api.post('save_users_settings', newSettings).then(()=>{
      newSettings.page = document.querySelector('.bt__pagination input').value;

      this.updateTableData(newSettings);
      this.settings = newSettings;
    });


  }
}

export default Settings;