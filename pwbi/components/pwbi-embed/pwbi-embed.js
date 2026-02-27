((Drupal) => {
  /**
   * Schedule a token refresh before the current embed token expires.
   *
   * Reads the JWT exp claim to calculate when to fire. Adds random jitter to
   * prevent a thundering herd when many users load the same cached page.
   * Also registers a visibilitychange listener so a token that expired while
   * the tab was backgrounded is refreshed immediately on return.
   *
   * @param {HTMLElement} embedContainer - The container element for the embed.
   * @param {object} embedSettings - The drupalSettings entry for this report.
   * @param {object} powerbiClient - The global powerbi client object.
   */
  function scheduleTokenRefresh(embedContainer, embedSettings, powerbiClient) {
    const arrayToken = embedSettings.accessToken.split('.');
    if (arrayToken.length < 2) {
      return;
    }
    const tokenPayload = JSON.parse(atob(arrayToken[1]));
    if (tokenPayload?.exp === undefined) {
      return;
    }
    const expMs = tokenPayload.exp * 1000;
    const refreshMinutes = embedSettings.token_refresh_minutes ?? 10;
    const refreshMs = refreshMinutes * 60 * 1000;
    // Add up to 30 seconds jitter to prevent thundering herd.
    const jitter = Math.random() * 30000;
    const delay = expMs - Date.now() - refreshMs + jitter;

    if (delay <= 0) {
      // Token already close to expiry or past; refresh immediately.
      performTokenRefresh(embedContainer, embedSettings, powerbiClient);
      return;
    }

    // Both the timer and the visibility listener are set up together so each
    // can cancel the other, preventing duplicate refreshes and listener leaks.
    let visibilityHandler;

    const timerId = setTimeout(() => {
      document.removeEventListener('visibilitychange', visibilityHandler);
      performTokenRefresh(embedContainer, embedSettings, powerbiClient);
    }, delay);

    // Also refresh when tab becomes visible (catches expiry during inactivity).
    visibilityHandler = function onVisible() {
      if (document.visibilityState === 'visible') {
        const nowMs = Date.now();
        if (nowMs >= expMs - refreshMs) {
          clearTimeout(timerId);
          document.removeEventListener('visibilitychange', visibilityHandler);
          performTokenRefresh(embedContainer, embedSettings, powerbiClient);
        }
      }
    };
    document.addEventListener('visibilitychange', visibilityHandler);
  }

  /**
   * Fetch a fresh embed token from Drupal and apply it to the live report.
   *
   * Uses `powerbi.get(embedContainer)` (Microsoft's recommended pattern) to
   * obtain the live report reference. After applying the new token the refresh
   * cycle is rescheduled so the embed stays alive indefinitely.
   *
   * @param {HTMLElement} embedContainer
   * @param {object} embedSettings
   * @param {object} powerbiClient
   */
  async function performTokenRefresh(embedContainer, embedSettings, powerbiClient) {
    const { workspaceId, id: reportId, datasetId } = embedSettings;
    try {
      const base = drupalSettings.path.baseUrl;
      const refreshUrl = `${base}pwbi/token-refresh/${workspaceId}/${reportId}?dataset_id=${encodeURIComponent(datasetId)}`;
      const response = await fetch(refreshUrl, {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
        },
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

      // Use powerbi.get() to retrieve the live report reference.
      const liveReport = powerbiClient.get(embedContainer);
      await liveReport.setAccessToken(data.token);

      // Update local embedSettings and reschedule for the next cycle.
      embedSettings.accessToken = data.token;
      scheduleTokenRefresh(embedContainer, embedSettings, powerbiClient);
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
        const arrayToken = embedSettings.accessToken.split('.');
        if (arrayToken.length < 2) {
          return;
        }
        const tokenPayload = JSON.parse(atob(arrayToken[1]));
        if (tokenPayload?.exp === undefined) {
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
        // Embed the visual.
        const PowerBiPostEmbed = new CustomEvent('PowerBiPostEmbed', {
          detail: report,
        });
        report.on('loaded', () => {
          report.render();
        });
        report.on('rendered', () => {
          window.dispatchEvent(PowerBiPostEmbed);
          // Start token refresh timer if enabled.
          if (embedSettings.token_refresh_enabled) {
            scheduleTokenRefresh(embedContainer, embedSettings, powerbiClient);
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
