# Stage File Proxy

Mirror (or header to) uploaded files from a remote production site on your local development copy. Saves the trouble of downloading a giant uploads directory without sacrificing the images that accompany content.

## Setup

Stage File Proxy runs when WordPress is serving a 404 response for a request to the uploads directory. If your server intercepts these requests instead of passing them to WordPress, the plugin will not work.

There is no UI for the plugin. Options must be set manually, typically using WP-CLI. For example, it looks like this to set the mode to `header`:

```shell
wp option update sfp_mode header
```

## Available options

* `sfp_mode`: The method used to retrieve the remote image. Default is `header`. One of:
  * `download` (downloads the remote file to your machine)
  * `header` (serves the remote file directly)
  * `local` (like `download` but serves an image from a directory in the current parent theme if the download fails)
  * `photon` (like `header` but uses arguments compatible with []() to size the image)

* `sfp_url`: The absolute URL to the uploads directory on the source site.

* `sfp_local_dir`: The name of the directory in the parent theme where images are stored for `local` mode.
