# Power BI disclaimer banner

This submodule allows to create banners to show them when displaying embeds from Power Bi.

## CONFIGURATION

1. Go to admin/config/pwbi/pwbi_banner and configure the banner text and behaviour.
2. To show as a block, also add the block "Power Bi disclaimer block" in admin/structure/block

## CREATING YOUR OWN BANNER

You can create your own banner to populate the pwbi-embed-overlay-top and pwbi-embed-overlay-blocking divs.
To do this you have to:
1. Create an event subscriber. See Drupal\pwbi_banner\EventSubscriber::PwbiEmbedOverlaysSubscriber
2. Create a component for the data to show and the desired functionality
3. Add your component js as a dependency for pwbi-embed component. See pwbi_banner_library_info_alter() in
modules/pwbi_banner/pwbi_banner.module.
