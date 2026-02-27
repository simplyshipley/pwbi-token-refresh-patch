((Drupal) => {
  const CookieManager = (() => {
    // Function to create or update a cookie
    const setCookie = (name, value, days = 7, domain = '', path = '/') => {
      let expires = '';
      if (days) {
        const date = new Date();
        date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
        expires = `; expires=${date.toUTCString()}`;
      }
      const domainStr = domain ? `; domain=${domain}` : '';
      const pathStr = path ? `; path=${path}` : '';
      document.cookie = `${name}=${encodeURIComponent(value)}${expires}${domainStr}${pathStr}`;
    };

    // Function to delete a cookie
    const deleteCookie = (name, domain = '', path = '/') => {
      setCookie(name, '', -1, domain, path);
    };

    // Function to get the value of a cookie
    const getCookie = (name) => {
      const nameEQ = `${name}=`;
      const cookies = document.cookie.split(';');
      const match = cookies
        .map((cookie) => cookie.trim())
        .find((cookie) => cookie.indexOf(nameEQ) === 0);
      if (match) {
        return decodeURIComponent(match.substring(nameEQ.length));
      }
      return null;
    };

    return {
      setCookie,
      deleteCookie,
      getCookie,
    };
  })();
  const pwbiBannerCookie = {
    cookieName: 'Drupal.pwbi_banner.accepted_disclaimer',
    bannerSelector: '.pwbi-disclaimer-banner',
    bannerAction: '.pwbi-disclaimer-banner .pwbi-disclaimer-accept button',
    domain: '',
    days: 7,
    isMustHave: false,
    powerBiEmbed: {},
    acceptDisclaimer() {
      CookieManager.setCookie(this.cookieName, 'true', this.days, this.domain);
      this.removeBanners();
    },
    isDisclaimerAccepted() {
      return CookieManager.getCookie(this.cookieName) === 'true';
    },
    removeBanners() {
      document.querySelectorAll(this.bannerSelector).forEach((banner) => {
        banner.remove();
      });
    },
    showBanners() {
      document.querySelectorAll(this.bannerSelector).forEach((banner) => {
        banner.style.display = 'block';
      });
    },
    isBlocked() {
      return this.isMustHave && this.isDisclaimerAccepted() === false;
    },
    loadPowerBi() {
      if (
        this.isBlocked() === false &&
        this.powerBiEmbed.callback instanceof Function
      ) {
        this.powerBiEmbed.callback(
          this.powerBiEmbed.pwbi_embed,
          this.powerBiEmbed.powerbi,
        );
      }
    },
    setEvents(settings) {
      document.addEventListener('pwbi_embed_block', (e) => {
        e.detail.block = this.isBlocked();
        this.powerBiEmbed = e.detail.powerBiEmbed;
        this.loadPowerBi();
      });
      if (Array.isArray(settings.pwbi_banner)) {
        settings.pwbi_banner.forEach((value, index) => {
          if (value) {
            this[index] = value;
          }
        });
      }
      if (this.isDisclaimerAccepted() === true) {
        this.removeBanners();
        return;
      }
      document.querySelectorAll(this.bannerAction).forEach((acceptButton) => {
        acceptButton.addEventListener('click', () => {
          this.acceptDisclaimer();
          this.loadPowerBi();
        });
      });
    },
  };

  Drupal.behaviors.pwbi_banner = {
    attach(context, settings) {
      if (!settings.pwbi_banner.flag) {
        settings.pwbi_banner.flag = true;
        if (
          settings.pwbi_banner.must_have &&
          settings.pwbi_banner.must_have === 1
        ) {
          pwbiBannerCookie.isMustHave = true;
        }
        pwbiBannerCookie.setEvents(settings);
      }
    },
  };
})(Drupal);
