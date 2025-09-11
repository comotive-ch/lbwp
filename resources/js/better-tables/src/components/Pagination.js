import API from "../classes/API";

function Pagination({current, total, per_page, setPage, setRows}){
  const pages = Math.ceil(total / per_page);
  const ajax = new API('http://crm.swsmedien.local/wp-json/lbwp/bettertables/');
  const goToPage = (page) => {
    switch (page) {
      case 'next':
        current += 1;
        setPage(current);
        break;

      case 'prev':
        current -= 1;
        setPage(current);
        break;

      default:
        setPage(page);
        break;
    }

    let ajaxArgs = {
      per_page: per_page,
      page: current,
      search: [],
      search_column: []
    };
    document.querySelectorAll('.bt__table--input').forEach((input) => {
      if (input.value !== '') {
        ajaxArgs.search.push(input.value);
        ajaxArgs.search_column.push(input.name);
      }
    });

    if(ajaxArgs.search.length <= 0){
      delete ajaxArgs.search;
    }

    if(ajaxArgs.search_column.length <= 0){
      delete ajaxArgs.search_column;
    }

    ajax.get('users', ajaxArgs).then((response) => {
      response.json().then((data) => {
        setRows(data.rows);
        document.querySelector('#react-root table').classList.remove('loading');
      });
    });
  }

  const pageBtn = (num) => {
    return <span onClick={() => goToPage(num)}>{num}</span>;
  }

  const changePage = () => {
    return(
      <>
        <input value={current} onChange={(e) => goToPage(e.target.value)} />
        <span> von {pages}</span>
      </>
    );
  }

  return(
    <div className={"bt__pagination"}>
      <button className={"prev-page button"} onClick={() => goToPage('prev')} disabled={current <= 1}><span aria-hidden="true">‹</span></button>
      <div className="pages">
      {changePage()}
      </div>
      <button className={"next-page button"} onClick={() => goToPage('next')} disabled={current >= pages}><span aria-hidden="true">›</span></button>
    </div>
  );
}

export default Pagination;