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

## License

MIT.
