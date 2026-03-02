((Drupal) => {
  // Minutes before token expiration to trigger a refresh.
  const MINUTES_BEFORE_EXPIRATION = 10;

  // How often (ms) to poll and check whether a refresh is needed.
  // 30 seconds matches the Microsoft-recommended interval.
  const INTERVAL_TIME = 30000;

  /**
   * Check whether the token is within the refresh window and update if so.
   *
   * Called on a 30-second interval and also immediately when the tab becomes
   * visible again after being backgrounded. Mirrors the pattern in Microsoft's
   * token refresh documentation.
   *
   * @param {object} embedSettings - drupalSettings entry for this report.
   * @param {object} report - The Report object returned by powerbiClient.load().
   */
  function checkTokenAndUpdate(embedSettings, report) {
    const currentTime = Date.now();
    const expiration = Date.parse(embedSettings.tokenExpiration);
    const timeUntilExpiration = expiration - currentTime;
    const minutesBefore = embedSettings.token_refresh_minutes ?? MINUTES_BEFORE_EXPIRATION;
    const timeToUpdate = minutesBefore * 60 * 1000;

    if (timeUntilExpiration <= timeToUpdate) {
      console.log('[pwbi] Token expiring soon, refreshing...');
      updateToken(embedSettings, report);
    }
  }

  /**
   * Fetch a fresh embed token from Drupal and apply it to the live report.
   *
   * Passes the report object directly (captured from powerbiClient.load()) so
   * there is no fragile DOM re-lookup via powerbi.get(). After a successful
   * refresh, embedSettings.tokenExpiration is updated so subsequent
   * checkTokenAndUpdate() calls use the new expiry window.
   *
   * @param {object} embedSettings - drupalSettings entry for this report.
   * @param {object} report - The Report object returned by powerbiClient.load().
   */
  async function updateToken(embedSettings, report) {
    const { workspaceId, id: reportId, datasetId } = embedSettings;
    const base = drupalSettings.path.baseUrl;
    const refreshUrl = `${base}pwbi/token-refresh/${workspaceId}/${reportId}?dataset_id=${encodeURIComponent(datasetId)}`;

    try {
      const response = await fetch(refreshUrl, {
        headers: { 'Accept': 'application/json' },
      });

      if (!response.ok) {
        console.error('[pwbi] Token refresh HTTP error', response.status);
        return;
      }

      const data = await response.json();
      if (!data.token) {
        console.error('[pwbi] Token refresh response missing token');
        return;
      }

      // Update the tracked expiration so future interval checks use the new window.
      embedSettings.tokenExpiration = data.expiration;

      // Apply the new token directly to the report reference we already hold.
      await report.setAccessToken(data.token);

      console.log('[pwbi] Token refreshed. New expiration:', data.expiration);
    }
    catch (err) {
      console.error('[pwbi] Token refresh failed', err);
    }
  }

  const pwbiEmbed = {
    runEmbeds(pwbiEmbeds, powerbiClient) {
      Object.entries(pwbiEmbeds).forEach((entry) => {
        const [key, embedSettings] = entry;
        const embedContainer = document.getElementById(embedSettings.id);
        // powerbi is the global object of the powerbi-client library.
        const alreadyEmbedded = Object.values(powerbiClient.embeds).some(
          (embed) => embed.element.id === embedSettings.id,
        );
        if (alreadyEmbedded) {
          return;
        }

        const embedConfiguration = embedSettings;
        if (embedConfiguration.settings === undefined) {
          embedConfiguration.settings = {};
        }

        // Get a reference to the HTML element that contains the embedded report.
        const powerBiEmbedParams = {
          embedConfiguration,
          embedContainer,
        };
        // Embed the visual.
        const PowerBiPreEmbed = new CustomEvent('PowerBiPreEmbed', {
          detail: powerBiEmbedParams,
        });
        window.dispatchEvent(PowerBiPreEmbed);
        const report = powerbiClient.load(
          powerBiEmbedParams.embedContainer,
          powerBiEmbedParams.embedConfiguration,
        );
        const PowerBiPostEmbed = new CustomEvent('PowerBiPostEmbed', {
          detail: report,
        });
        report.on('loaded', () => {
          report.render();
        });
        report.on('rendered', () => {
          window.dispatchEvent(PowerBiPostEmbed);
          // Start the token refresh polling on first render only. The `rendered`
          // event fires on every re-render (filters, drill-through, page turns)
          // so the _refreshScheduled guard prevents duplicate intervals stacking.
          if (embedSettings.token_refresh_enabled && !embedSettings._refreshScheduled) {
            embedSettings._refreshScheduled = true;

            // Seed tokenExpiration from the server-rendered value so the first
            // interval check has a baseline before any refresh has occurred.
            embedSettings.tokenExpiration = embedSettings.tokenExpirationDate;

            // Poll every 30 seconds — matches Microsoft's recommended pattern.
            setInterval(() => {
              checkTokenAndUpdate(embedSettings, report);
            }, INTERVAL_TIME);

            // Also check immediately when the tab becomes visible again so a
            // token that expired while the tab was backgrounded is caught fast.
            document.addEventListener('visibilitychange', () => {
              if (!document.hidden) {
                checkTokenAndUpdate(embedSettings, report);
              }
            });
          }
        });
      });
    },
    setEvents(settings, powerbiClient) {
      const powerBiEmbed = {
        callback: this.runEmbeds,
        pwbi_embed: settings.pwbi_embed,
        powerbi: powerbiClient,
      };
      const blockEmbedEvent = new CustomEvent('pwbi_embed_block', {
        detail: {
          block: false,
          powerBiEmbed,
        },
      });
      document.addEventListener('pwbi_embed_block', (e) => {
        if (e.detail.block === false) {
          this.runEmbeds(settings.pwbi_embed, powerbiClient);
        }
      });
      document.dispatchEvent(blockEmbedEvent);
    },
  };

  Drupal.behaviors.pwbi_embed = {
    attach(context, settings) {
      if (!settings.pwbi_flag) {
        const waitForGlobal = function (key, settings) {
          if (window[key]) {
            pwbiEmbed.setEvents(settings, window[key]);
          } else {
            setTimeout(function () {
              waitForGlobal(key, settings);
            }, 100);
          }
        };
        settings.pwbi_flag = true;
        waitForGlobal('powerbi', settings);
      }
    },
    detach() {},
  };
})(Drupal);
