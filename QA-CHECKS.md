# QA checks for an expired dashboard

Copy each block with the copy button at its top right. Run the console blocks in the browser tab where the dashboard died, before reloading it. None of them print a token.

## 1. What the page was given (browser console)

```js
console.table(Object.values(drupalSettings.pwbi_embed).map(e => ({
  served: e.tokenExpirationDate,
  tracked: e.tokenExpiration,
  refreshEnabled: e.token_refresh_enabled,
  leadMs: e.token_refresh_lead_ms,
  scheduled: e._refreshScheduled,
  retries: e._refreshRetries,
  refreshing: e._refreshing,
  pageLoaded: new Date(performance.timeOrigin).toISOString(),
  now: new Date().toISOString(),
})));
```

- `served` is the token expiry Drupal put in the page. `tracked` is the expiry the refresh script is following.
- `served` earlier than `pageLoaded` means the page arrived with a dead token.
- `scheduled` empty means the report never rendered, so no refresh was set up.

## 2. Is the server still sending the old token? (browser console)

This asks the server for a new copy of the same page, skipping the browser's own cache, and prints the cache headers and the token expiry in that copy.

```js
fetch(location.href, {cache: 'no-store', credentials: 'same-origin'}).then(async (r) => {
  const t = await r.text();
  console.log([...r.headers].filter(([k]) => /drupal|^age$|cache|expires|^date$|via|etag/i.test(k)));
  console.log(t.match(/"tokenExpirationDate":"[^"]+"/g));
});
```

- Expiry still in the past: the server (or something in front of it) is storing the page or the dashboard block.
- Expiry about an hour ahead: the server is fine and the browser showed a saved copy.

## 3. Same check from a terminal, logged out

Replace the URL with the dashboard page. Run it twice, a few seconds apart.

```bash
curl -s -D - -o /tmp/pwbi-page.html "https://QA-SITE/DASHBOARD-PAGE" | grep -i -E 'x-drupal|x-cache|x-azure-ref|^age:|cache-control|expires|^date:|via|etag'; grep -o '"tokenExpirationDate":"[^"]*"' /tmp/pwbi-page.html
```

Reading the `X-Cache` header, which Azure Front Door adds ([Microsoft: Front Door caching](https://learn.microsoft.com/en-us/azure/frontdoor/front-door-caching)):

- `TCP_HIT` or `TCP_REMOTE_HIT`: Front Door served the page from its own cache. Drupal was not asked.
- `TCP_MISS`: Front Door fetched the page from Drupal, and may now store it.
- `PRIVATE_NOSTORE`: Front Door did not store the page because Drupal marked it `private` or `no-store`.
- `CONFIG_NOCACHE`: caching is switched off for this route in Front Door.

A dashboard page should show `PRIVATE_NOSTORE` or `CONFIG_NOCACHE`. `TCP_HIT` with an expired token means Front Door is holding an old copy: purge that path in Front Door, then run the check again twice.

## 4. Is the page-cache rule deployed? (on the QA server)

```bash
drush php:eval "print class_exists('Drupal\\pwbi\\PageCache\\DenyPowerBiEmbedPages') ? 'yes' : 'no';"
```

`no` means QA is running the patch without the page-cache rule, and Drupal's internal page cache can keep a dashboard page until a cache tag is cleared.

## 5. Stored settings (on the QA server)

```bash
drush php:eval "\$s = \Drupal::config('pwbi.settings'); print 'refresh_enabled: ' . (\$s->get('token_refresh_enabled') ? 'yes' : 'no') . PHP_EOL . 'lead_ms: ' . \$s->get('token_refresh_lead_ms') . PHP_EOL . 'page_cache_max_age: ' . \Drupal::config('system.performance')->get('cache.page.max_age') . PHP_EOL;"
```

## 6. Which pwbi parts are enabled (on the QA server)

```bash
drush pm:list --status=enabled --format=list | grep -i -E 'pwbi|page_cache|big_pipe|purge|varnish'
```

## 7. Refresh requests the tab made (browser console)

Lists every call the tab made to the refresh endpoint, with its status. An empty list means the script never asked for a new token.

```js
console.table(performance.getEntriesByType('resource').filter(e => e.name.includes('/pwbi/token-refresh/')).map(e => ({
  at: new Date(performance.timeOrigin + e.startTime).toISOString(),
  status: e.responseStatus,
  ms: Math.round(e.duration),
})));
```

## What to send back

A photo or paste of the output of 1, 2, 4, 5 and 6, plus two facts: whether the tab was logged in, and how the dashboards are placed on the page (media field on a node, a View, a block, Layout Builder).
