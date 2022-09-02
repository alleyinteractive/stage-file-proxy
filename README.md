# stage-file-proxy

Mirror (or header to) uploaded files from a remote production site on your local development copy. Saves the trouble of downloading a giant uploads directory without sacrificing the images that accompany content.

# Setup
1. It relies upon WP getting the 404 for a missing media asset, so whatever server you run this on it must not intercept 404.
2. There is no control panel for this plugin, so options must be set manually. Using WP-CLI for example, it looks like this to set the mode the `header`:

```shell
$ wp option set sfp_mode header
```


## Options
These are options stored in the WP options table.

* `sfp_mode`: <br>The method used to retrieve the remote image. One of
  * `download` <br>Download the remote image to your machine
  * `header` <br>serve the remote file directly without downloading
  * `local` <br>Not sure, but it looks like it uses a local file if the remote get fails
  * `photon`<br>Not sure, but looks like it uses the photos service to dynamically get images at a specific size
  * `lorempixel` <br> Not sure


* `sfp_url`<br>the URL to the uploads directory on the source site. The full url, not relative.

* `sfp_local_dir`<br>Not sure, but looks like it's a folder used by this plugin to store files locally for transient/caching purposes