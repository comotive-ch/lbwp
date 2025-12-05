import { ROOT_ID } from "../constants.js?v=2";

/**
 * API (ajax wrapper) class to handle requests to the backend
 */
export class API {
  constructor(url) {
    this.baseUrl = url;
  }

  /**
   * Make a GET request to the specified endpoint with optional parameters
   * @param endpoint
   * @param params
   * @returns {Promise<Response>}
   */
  get(endpoint, params = {}) {
    endpoint += '?user_id=' + lbwpBetterTables.user_id + '&';

    // check if object is empty
    if (Object.keys(params).length > 0) {
      let table = document.querySelector(ROOT_ID + ' table');

      if (table !== null) {
        table.classList.add('loading');
      }

      for (const [key, value] of Object.entries(params)) {
        endpoint += key + '=' + value + '&';
      }
    }

    return this.fetch(endpoint, 'GET');
  }

  /**
   * Make a POST request to the specified endpoint with data
   * @param endpoint
   * @param data
   * @returns {Promise<Response>}
   */
  post(endpoint, data) {
    return this.fetch(endpoint, 'POST', data);
  }

  /**
   * Generic fetch method to handle both GET and POST requests
   * @param endpoint
   * @param method
   * @param data
   * @returns {Promise<Response>}
   */
  fetch(endpoint, method = 'GET', data = {}) {
    data.user_id = lbwpBetterTables.user_id;
    if (method === 'GET') {
      let time = new Date();
      return fetch(this.baseUrl + endpoint + '&cache=' + time.getTime());
    } else {
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