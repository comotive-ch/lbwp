var sw = {
  version: {version},
  cacheName: 'sw_cache_v' + {version},
  cachedItems: '{cachePaths}',
  excludes: '{excludePaths}',
  cacheDelay: 1000,
  preventCache: '{preventCache}'
}

sw.cachedItems = sw.cachedItems.split(',');

if (sw.excludes.length === 1 && sw.excludes[0] === '') {
  sw.excludes = false;
} else {
  sw.excludes = sw.excludes.split(',');
}

self.addEventListener('install', function (event) {
  function onInstall(event) {
    removeOldCache();

    caches.open(sw.cacheName).then(function (cache) {
      function cacheUrl(index, url) {
        return new Promise(function (resolve, reject) {
          setTimeout(function () {
            cache.add(url)
          }, sw.cacheDelay * index);
        });
      }

      for (let i = 1; i <= sw.cachedItems.length; i++) {
        Promise.resolve(cacheUrl(i, sw.cachedItems[i - 1]));
      }
    });
  }

  function removeOldCache() {
    caches.keys().then(keys => Promise.all(
      keys.map(key => {
        if (key !== sw.cacheName) {
          return caches.delete(key);
        }
      })
    ))
  }

  event.waitUntil(
    onInstall(event).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    self.clients.claim(),
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cache) => {
          if (cache !== sw.cacheName) {
            return caches.delete(cache);
          }
        })
      );
    })
  );
});

self.addEventListener('fetch', function (event) {
  // If set, ignore local cache
  if(sw.preventCache === '1'){
    return event.respondWith(fetch(event.request));
  }

  // Exclude admin url and manifest
  if (
    !(event.request.url.indexOf('http') === 0) ||
    event.request.url.indexOf('/wp-admin') !== -1 ||
    event.request.url.indexOf('wp-login.php') !== -1 ||
    (event.request.url.indexOf('manifest') !== -1 && event.request.url.indexOf('.json') !== -1)
  ) {
    return false;
  }

  // Exclude defined url / paths
  if (sw.excludes !== false) {
    for (var i = 0; i < sw.excludes.length; i++) {
      if (event.request.url.indexOf(sw.excludes[i]) !== -1) {
        return false;
      }
    }
  }

  event.respondWith(caches.match(event.request).then(function (response) {
    // caches.match() always resolves
    // but in case of success response will have value
    if (response !== undefined) {
      return response;
    } else {
      return fetch(event.request).then(function (response) {
        if (event.request.method !== 'GET') {
          return response;
        }

        // response may be used only once
        // we need to save clone to put one copy in cache
        // and serve second one
        let responseClone = response.clone();

        caches.open(sw.cacheName).then(function (cache) {
          cache.put(event.request, responseClone);
        });
        return response;
      }).catch(function () {
        return caches.match('/');
      });
    }
  }));
});

/**
 * Listen for push notifications with eventual url
 */
self.addEventListener('push', function (event) {
  console.log(event);
  let data = JSON.parse(event.data.text());
  let options = {
    body: data.message,
    icon: data.icon,
    data: {url: data.url}/*,
    actions dont close the notification pane on android, thus disabled for the moment
    actions: [{
      action: 'open_url',
      title: data.title
    }]*/
  };

  event.waitUntil(
    Promise.all([
      self.registration.showNotification(data.title, options),
    ])
  );
});

/**
 * Go to according url given in notification if it has been clicked
 */
self.addEventListener('notificationclick', function(event) {
  if (typeof(event.notification.data.url) == 'string') {
    event.waitUntil(clients.matchAll({
      type: "window",
      includeUncontrolled: true
    }).then(function (clientList) {
      let client = null;
      for (let i = 0; i < clientList.length; i++) {
        let item = clientList[i];
        if (item.url) {
          client = item;
          break;
        }
      }
      if (client && 'navigate' in client) {
        client.focus();
        event.notification.close();
        return client.navigate(event.notification.data.url);
      }
      else {
        event.notification.close();
        return clients.openWindow(event.notification.data.url);
      }
    }));
  }
});

{additionalCode}