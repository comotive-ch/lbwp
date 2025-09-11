class API {
  constructor(url) {
    this.baseUrl = url;
  }

  get(endpoint, params = {}) {
    endpoint += '?user_id=' + lbwpBetterTables.user_id + '&';

    // check if object is empty
    if(Object.keys(params).length > 0){
      let table = document.querySelector('#react-root table');

      if(table !== null) {
        table.classList.add('loading');
      }

      for(const [key, value] of Object.entries(params)) {
        endpoint += key + '=' + value + '&';
      }
    }

    return this.fetch(endpoint, 'GET');
  }

  post(endpoint, data){
    return this.fetch(endpoint, 'POST', data);
  }

  fetch(endpoint, method='GET', data = {}) {
    data.user_id = lbwpBetterTables.user_id;
    if(method === 'GET') {
      let time = new Date();
      return fetch(this.baseUrl + endpoint + '&cache=' + time.getTime());
    }else{
      return fetch(this.baseUrl + endpoint, {
        method: method,
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
      });
    }
  }
}

export default API;