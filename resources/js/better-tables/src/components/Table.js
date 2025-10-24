import { useState, useRef, useEffect } from 'react';
import API from '../classes/API.js';
import Pagination from "./Pagination";

// Tutorial: https://www.sitepoint.com/create-sortable-filterable-table-react/

function Table({data, settings}){
  const [sortedRows, setRows] = useState(data.rows);
  const [totalPages, setTotalPages] = useState(data.total);
  const ajax = new API('http://crm.swsmedien.local/wp-json/lbwp/bettertables/');
  const perPage = settings.per_page;
  const debounceTimeout = useRef(null);
  const [debouncedFilter, setDebouncedFilter] = useState(null);
  const [curPage, setCurPage] = useState(1);

  // Update table if rows change
  useEffect(() => {
    setRows(data.rows);
  }, [data]);

  const filter = (event: React.ChangeEvent<HTMLInputElement>) => {
    let ajaxArgs = {
      per_page: perPage,
      page: curPage,
      search: [],
      search_column: []
    };

    document.querySelectorAll('.bt__table--input').forEach((input) => {
      if (input.value !== '') {
        ajaxArgs.search.push(input.value);
        ajaxArgs.search_column.push(input.name);
      }

      setCurPage(1);
    });

    // Clear the previous timeout if there is one
    if (debounceTimeout.current) {
      clearTimeout(debounceTimeout.current);
    }

    // Set a new timeout
    debounceTimeout.current = setTimeout(() => {
      setDebouncedFilter(ajaxArgs);
    }, 500); // 500ms delay
  };

  useEffect(() => {
    if (debouncedFilter !== null) {
      ajax.get('users', debouncedFilter).then((response) => {
        response.json().then((data) => {
          setRows(data.rows);
          setTotalPages(data.total);
          console.log(data.total);
          document.querySelector('#react-root table').classList.remove('loading');
        });
      });
    }
  }, [debouncedFilter]);

  const tableHead = Object.keys(data.columns).map((column, index) => {
    return (
      <th key={index}>
        <input
          className={"bt__table--input"}
          type="text"
          name={column}
          placeholder={data.columns[column]}
          onChange={filter}/>
      </th>
    );
  });

  const rows = sortedRows.length > 0 ? sortedRows.map((row, rowIndex) => {
    return (
      <tr key={rowIndex}>
        {row.map((cell, index) => {
          return <td key={index} dangerouslySetInnerHTML={{ __html: cell }}></td>;
        })}
      </tr>
    );
  }) : <tr><td colSpan={data.columns.length}>No results found</td></tr>;

  return(
    <>
      <div className={"bt__table--container"}>
        <table className={"bt__table"}>
          <thead>
          <tr>{tableHead}</tr>
          </thead>
          <tbody>{rows}</tbody>
        </table>
      </div>
      <Pagination current={curPage} total={totalPages} per_page={perPage} setPage={setCurPage} setRows={setRows}/>
    </>
  );
}

export default Table;