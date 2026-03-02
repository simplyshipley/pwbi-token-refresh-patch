# Power BI Integration

Provides tools to integrate PowerBi into Drupal

## INTRODUCTION

These modules provide the tools to integrate PowerBi into Drupal, with
the different modules you will be able to access the embed and RestAPI
of PowerBi.
This is the process workflow and the role of each of the modules in it:
![pwbi](./img/pwbi_workflow.png)

The functionality is provided by three components:
- Authentication:
    - The plugins PwbiServicePrincipal and ServicePrincipal provide
authentication to Power Bi
- REST API service:
    - Provided by pwbi_api module
- Media Type:
    - Provided by pwbi_embed, allows creating media types to manage embedded
objects

## REQUIREMENTS

- [OAuth2 Client] https://www.drupal.org/project/oauth2_client
- [powerbi-client] https://github.com/microsoft/PowerBI-JavaScript.

## INSTALLATION

Install as you would normally install a contributed Drupal module. For further
information, see
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).

To install the Power Bi javascript library (powerbi-client) use one of these
methods:

### PREFERRED METHOD:
PowerBi Embed provides a package.json to include the
powerbi-client library. Make sure you have NPM of Node.js
installed in your system and from the module folder, run `npm install`.

Alternatively, in your composer add the following script in your
post-install-cmd scripts in order to install the
dependencies with each `composer install`.
```json
{
  "scripts": {
    "post-install-cmd": [
      "npm install -C web/modules/contrib/pwbi"
    ]
  }
}
```
In the script above, it is assumed that the `pwbi`
module is installed in the `web/modules/contrib` directory as
a relative path from the project root or where your `composer.json`
is located. Adapt the path according to your installation.

#### COMPOSER
You can use composer to download the powerbi js client by taking these steps:

1. Run the following command to ensure that you have the "composer/installers"
   package installed:

```
        composer require --prefer-dist composer/installers

```
2. Add the following to the "installer-paths" section of "composer.json":

```
        "libraries/{$name}": ["type:drupal-library"],
```
3. Add the following to the "repositories" section of "composer.json":
```
   {
       "type": "package",
       "package": {
           "name": "microsoft/powerbi",
           "version": "2.1.19",
           "type": "drupal-library",
           "dist": {
               "url": "https://github.com/microsoft/PowerBI-JavaScript/archive/refs/tags/v2.19.1.zip",
               "type": "zip"
           }
       }
   }
```
4. Run the following command; you should find that new directories have been
   created under "/libraries".
```
        composer require --prefer-dist microsoft/powerbi
```

### ALTERNATIVE METHODS:
Manually download and install the library,
from https://github.com/microsoft/PowerBI-JavaScript,
in the `libraries` folder in the webroot,
profile directory or site directory
within drupal. The package should be placed as such,
so that the path looks like
`<libraries path>/powerbi/dist/powerbi.min.js` where
`<libraries path>` is one of the directories mentioned above.


## CONFIGURATION

### SERVICE PRINCIPAL AUTHENTICATION
1. Give permissions to configure the module (Administer PowerBi configuration).
2. Configure "Power Bi service principal auth" oauth plugin: admin/config/system/oauth2-client.
    1. Fill the input for Tenant, Client secret and Client id

### AUTHENTICATION WITH CERTIFICATE
Using the same plugin "Power Bi service principal auth" authentication plugin, you can use a certificate for authentication.
Using this method, you don't need to fill the client secret input in the configuration page (admin/config/system/oauth2-client)
But you will need to upload a certificate file (with pem extension).
Instead of uploading the file, you can define the path to it in the settings.php file as:

```php

$config['oauth2_client.oauth2_client.pwbi_service_principal']['third_party_settings']['pwbi']['cert_file'] = '';

```

### CONFIGURE POWERBI EMBED
1. Configure available PowerBi workspaces: admin/config/pwbi/embed_settings
2. Create a Media Entity using the "PowerBi Embed" Media Type
3. Create Media content.

## CUSTOMIZING POWERBI EMBED

This module allows others to customize the js
embedding process. This is achieved by
two events trigger before and after embedding:
1. PowerBiPreEmbed: This event is triggered before
embedding the object and allows changing the configuration options,
   using something like this:
```js
    window.addEventListener("PowerBiPreEmbed",
        (e) => {
            const powerBiConfig = e.detail;
        }
    );
```
2. PowerBiPostEmbed: This event is triggered after
embedding the object and allows changing the embedded object,
   using something like this:
```js
    window.addEventListener("PowerBiPostEmbed",
        (e) => {
        const powerBiReport = e.detail;
    }
);
```
