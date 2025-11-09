# Gallery

Gallery is a small personal project to display a gallery of photos on a website. It is designed to sit lightly on the server, requiring no database. It's primarily written in PHP, with some Bash scripting.

## Getting Started

1. You can build locally, install dependencies, run the test suite, import any new images in `originals/`, and refresh the library metadata with this command:

```
composer build
```

2. Once the local site is built, launch the local development server (available at [http://localhost:8000](http://localhost:8000)):

```
composer serve
```

The local site will build a static version of the site as a public image gallery:

```
composer export
```

The resulting files are stored in `build/`, and can be uploaded to the host.

To copy the static export to a remote server, configure `$deployment_host` and `$deployment_path` in `settings.inc.php`, then run:

```
composer deploy
```

The script uses `scp -r` to copy the contents of `build/` into the configured remote directory.
If you need to pass custom SSH options (for example, a specific private key), set `$deployment_ssh_options` in `settings.inc.php`.

`composer build` now writes an XML sitemap to `build/sitemap.xml`. Set `$site_base_url` in `settings.inc.php` so that the sitemap uses the correct canonical domain.

### Optional Alt Text Generation

If you set `$openai_api_key` in `settings.inc.php`, the ingestion pipeline will request descriptive alt text for new photos using OpenAI’s API (the original image is sent to the API to generate the description). Leave the key empty to skip automatic generation.

## License

MIT.
