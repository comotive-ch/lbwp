import {useState, useEffect, useRef} from "react";
import Table from "./components/Table";
import API from "./classes/API";
import Settings from "./classes/Settings";

function App() {
  const [data, setData] = useState(null);
  const [settingsFields, setSettingsFields] = useState(null);
  const ajax = useRef(new API(lbwpBetterTables.ajax_url)).current;
  const settings = useRef(new Settings(updateTableData)).current;

  function updateTableData(newSettings) {
    ajax.get('users', newSettings).then((response) => {
      response.json().then((data) => {
        setData(data);
        document.querySelector('#react-root table').classList.remove('loading');
      });
    });
  }

  function toggleAccordion(){
    const settingsContainer = document.querySelector('.bt__settings-container');
    settingsContainer.classList.toggle('collapsed');
  }

  useEffect(() => {
    settings.setup().then(() => {
      const fields = settings.displayFields();
      setSettingsFields(fields);
    }).then(()=>{
      ajax.get('users', settings.settings).then((response) => {
        response.json().then((initialData) => {
          setData(initialData);
        });
      });
    });
  }, []);

  return (
    <>
      <div className={"bt__settings-container collapsed"}>
        <h2 onClick={toggleAccordion}>Einstellungen</h2>
        {settingsFields ? settingsFields : 'Loading Settings...'}
      </div>
      {data && settings.settings ? <Table data={data} settings={settings.settings}/> : <p>Loading Table...</p>}
    </>
  );
}

export default App;