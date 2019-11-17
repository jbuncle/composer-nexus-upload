# Composer Nexus Upload/Push

## Usage

### Docker

```bash
docker run -it -v $(pwd):/workspace jbuncle/composer-nexus-upload:latest nexus-upload \
         --repository=https://example.nexus.repo.com/repository/composer-repo/ \
         --username=publisher-user \
         --password=$NEXUS_PASS \
         --version=$CI_COMMIT_TAG
```

### Inline Bash

```bash
php <(curl -s https://gist.githubusercontent.com/jbuncle/8e479343cfeb785046e3c6fe1a73dcce/raw/nexus-upload.php) \
         --repository=https://example.nexus.repo.com/repository/composer-repo/ \
         --username=publisher-user \
         --password=$NEXUS_PASS \
         --version=$CI_COMMIT_TAG
```

## CLI Arguments

| Argument   | Description                       |
|------------|-----------------------------------|
| repository | The nexus composer repository URL |
| username   | The nexus user name               |
| password   | The nexus user password           |
| version    | The composer version              |

## Files exclusion

To exclude files from the uploaded zip#

```json
"extra": {
        "nexus-upload": {
            "ignore": [
                "node_modules/",
                "*.css.map",
                "*.ts",
                "*.zip",
                "webpack.config.js",
                "*.json",
                "*.less"
            ]
        }
    }
```
