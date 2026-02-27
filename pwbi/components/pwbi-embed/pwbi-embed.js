((Drupal) => {
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
