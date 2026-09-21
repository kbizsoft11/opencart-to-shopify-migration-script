# OpenCart to Shopify Migration Project

This project is a PHP-based migration toolkit for moving product catalog data from OpenCart into Shopify. It is built for developers and operators who need a repeatable, auditable workflow for migrating products, variants, metadata, inventory, and images.

## What this project does

- Reads products from an OpenCart MySQL database
- Maps product fields to Shopify product payloads
- Handles product variants and options
- Preserves custom metadata using Shopify metafields
- Processes and maps product images for Shopify compatibility
- Produces CSV and report output for review and verification
- Supports dry-run validation before live Shopify updates
- Supports focused or bulk migration runs

## Main goals

- Safe migration workflow with validation before live publishing
- Minimal manual work for large catalog imports
- Support for variant-heavy catalogs
- Clear reporting for missing, skipped, or failed products
- Better operational control over image handling and inventory updates

## Core workflow

1. Load OpenCart config and database settings
2. Extract products and related data
3. Map OpenCart fields to Shopify product structure
4. Validate options, variants, image URLs, and metadata
5. Run a dry-run to inspect output
6. Send selected products or batches to Shopify
7. Audit results and fix any missing or incorrect entries

## Project structure

```text
oc_to_shopify_migration/
├── config.php
├── migrate.php
├── inspect_db.php
├── process_images.php
├── create_image_url_csv.php
├── batch_upload_missing.php
├── upload_missing_products.php
├── verify_shopify_products.php
├── audit_shopify_products.php
├── find_missing_products.php
├── manage_inventory.php
├── src/
│   ├── OpenCart/
│   ├── Mapper/
│   ├── Shopify/
│   ├── Reporter/
│   └── Utils/
├── examples/
├── output/
├── public/
├── api/
├── data/
├── .env
├── .env.example
├── .gitignore
├── README.md
└── other helper scripts
```

## Requirements

- PHP 8.0 or newer
- MySQL / MariaDB access
- PDO MySQL extension enabled
- Shopify Admin API access token
- Valid OpenCart database connection
- A working .env file with source and target credentials

## Environment setup

Create a .env file in the project root based on your local environment.

Example:

```env
OPENCART_DB_HOST=127.0.0.1
OPENCART_DB_PORT=3306
OPENCART_DB_NAME=your_database_name
OPENCART_DB_USER=root
OPENCART_DB_PASS=your_password
OPENCART_TABLE_PREFIX=oc_
OPENCART_LANGUAGE_ID=1
OPENCART_STORE_ID=0
OPENCART_IMAGE_DIR=/path/to/opencart/image
OPENCART_PUBLIC_BASE_URL=https://yourstore.com/image

SHOPIFY_STORE_DOMAIN=your-store.myshopify.com
SHOPIFY_ADMIN_ACCESS_TOKEN=shpat_xxxxxxxxxxxxxxxxxxxxxxxx
SHOPIFY_API_VERSION=2026-01
```

The application loads this configuration through config.php.

## Quick start

### 1. Validate database access

```bash
php inspect_db.php
```

This checks the OpenCart database and gives you visibility into product and category data before migration.

### 2. Run a dry-run migration

```bash
php migrate.php --dry-run --limit=20 --verbose
```

This is the safest starting point. It validates mapping and output without changing Shopify data.

### 3. Review generated reports

The migration writes reports to output folders. Always check these before sending anything live.

Typical outputs include:

- product summary CSV
- variant CSV
- image mapping files
- warnings and skipped products
- summary logs
- metadata and SEO-related reports

### 4. Run a live import in small batches

```bash
php migrate.php --send --limit=10 --verbose
```

Once results are acceptable, expand to a larger batch or full migration.

## Common commands

### Dry run

```bash
php migrate.php --dry-run --limit=10
php migrate.php --product-id=63 --dry-run --verbose
php migrate.php --dry-run --use-image-mapping --limit=20
```

### Live send

```bash
php migrate.php --send --limit=10
php migrate.php --send --update-existing --limit=20
php migrate.php --send --delete-all-products --verbose
php migrate.php --send --use-image-mapping --disable-tracking --verbose
```

### Image workflow

```bash
php process_images.php
php create_image_url_csv.php
php migrate.php --send --use-image-mapping
php migrate.php --send --use-image-csv --limit=50
```

### Product audits and checks

```bash
php verify_shopify_products.php
php audit_shopify_products.php
php find_missing_products.php
php check_inventory_status.php
php check_metafields.php
php manage_inventory.php
```

## Main CLI options

```bash
php migrate.php [options]
```

Available options include:

- --dry-run: validate without sending to Shopify
- --send: push products to Shopify
- --limit=N: only process N products
- --product-id=ID: process one specific OpenCart product
- --update-existing: update products already created in Shopify
- --delete-all-products: clear Shopify catalog before import
- --disable-tracking: set stock behavior for infinite or non-tracked inventory
- --process-images: process images locally before upload
- --use-image-mapping: use processed image mapping CSV
- --use-image-csv: use a simple CSV-based image mapping
- --image-host=URL: override the image host
- --verbose: show detailed runtime output
- --help: display help information

## Data mapping behavior

The system converts OpenCart data into Shopify-compatible payloads following a consistent mapping model:

- Product name → Shopify title
- Description → product body_html
- SKU / model → Shopify product or variant SKU
- Price → Shopify variant price
- Quantity → inventory quantity
- Manufacturer → vendor
- Categories → product type and tags
- SEO metadata → custom fields and product metadata
- Options → Shopify variants where supported

## Variant handling

OpenCart products can have multiple option combinations, but Shopify has limitations. The tool handles this in a controlled way:

- supports standard select and radio option types
- preserves first supported option groups up to Shopify limits
- skips unsupported combinations or logs warnings
- reports overly complex products for manual review

This prevents silent data corruption during migration.

## Image processing

Image handling is critical because Shopify has strict size and format constraints. The project includes workflows for:

- identifying oversized images
- processing local assets
- generating image mapping CSV files
- switching product imports to mapped images instead of raw OpenCart URLs

Use the image workflow before live import whenever image compliance is important.

## Output and reporting

Every migration run should be reviewed through the output data. These reports help catch issues early.

Common output locations:

- output/reports/
- output/image_processing/
- logs generated during migration runs

Look for:

- missing products
- duplicate or invalid mappings
- option warnings
- image processing failures
- inventory mismatches
- Shopify sync errors

## Recommended developer workflow

Use this process for most migration tasks:

1. Confirm .env values are correct
2. Run inspect_db.php
3. Run a small dry-run migration
4. Check reports and warnings
5. Fix mapping issues or data quality issues
6. Run a limited live batch
7. Verify results in Shopify
8. Increase batch size only after confirming stability

## Troubleshooting

Common problems include:

- wrong database credentials
- missing Shopify API credentials
- unsupported option structure
- image size or URL issues
- variant count exceeding Shopify limits
- products appearing as missing after import

Useful debugging commands:

```bash
php inspect_db.php
php find_missing_products.php
php check_metafields.php
php check_inventory_status.php
php verify_shopify_products.php
php audit_shopify_products.php
```

## Security notes

- Never commit .env files to Git
- Keep Shopify access tokens private
- Store credentials in local secure environment variables where possible
- Review generated reports before shipping to production

## Best practices

- Always begin with dry-run validation
- Migrate in batches, not giant live imports
- Review output reports after every run
- Keep image processing and Shopify limits in mind
- Treat metafields as a traceability layer for source data

## Summary

This project is designed for controlled and reliable OpenCart-to-Shopify migrations. It is especially valuable for product catalogs with multiple variants, custom fields, and image-heavy products where data quality and validation matter.

Use the dry-run flow first, validate the generated reports, then migrate in manageable batches to Shopify.


### Complexity Analysis

- **Max Options per Product:** 9 (exceeds Shopify's 3-option limit)
- **Max Option Values per Product:** 46
- **Max Additional Images per Product:** 25
- **Products with SEO URLs:** 1,753

### Recommendations

1. **Products with >3 options** (found): Review the `dry_run_skipped_options.csv` report
2. **Products with >100 variants**: Check `dry_run_warnings.csv` for manual review cases
3. **Category structure**: Plan Shopify collections before migration
4. **Image hosting**: Ensure images are publicly accessible or plan staged upload

## Troubleshooting

### Database Connection Failed
- Verify `.env` database credentials
- Ensure MySQL is running
- Check database name is correct
- Test with: `php inspect_db.php`

### No Products Found
- Check `OPENCART_LANGUAGE_ID` and `OPENCART_STORE_ID` in `.env`
- Verify table prefix is correct (`oc_` by default)

### Shopify API Errors
- Verify `SHOPIFY_STORE_DOMAIN` (format: `store.myshopify.com`)
- Check access token is valid and has required permissions
- Review API version compatibility

### Images Not Showing
- Set `OPENCART_PUBLIC_BASE_URL` in `.env`
- Ensure images are publicly accessible
- Check image paths in OpenCart database

### Rate Limit Errors
- Script includes automatic retry logic
- Shopify allows ~2 requests/second
- For large migrations, run in smaller batches

## Safety Features

**Read-Only Database Access** - Only SELECT queries on OpenCart  
**Dry-Run Default** - Must explicitly use `--send` to push to Shopify  
**Duplicate Detection** - Checks for existing products before creation  
**Validation** - Warns about Shopify limit violations  
**Detailed Logging** - Complete audit trail of all actions  
**Idempotent** - Safe to re-run without creating duplicates

## Next Steps After Dry-Run

1. Review all generated CSV reports
2. Check `dry_run_warnings.csv` for issues
3. Review `dry_run_skipped_options.csv` - plan alternative solutions
4. Check `shopify_payload_samples.json` for data quality
5. Plan Shopify collections based on OpenCart categories
6. Set up image hosting if needed
7. Configure Shopify credentials in `.env`
8. Test with `--send --limit=10`
9. Gradually increase batch size
10. Create URL redirects from `dry_run_seo_redirects.csv`

## Support & Contribution

This tool was built specifically for the `rachel_opencart` database but is designed to work with standard OpenCart 2.3+ installations.

### Customization

To adapt for your needs:
- Modify `src/Mapper/ProductMapper.php` for custom field mapping
- Extend `src/OpenCart/ProductExtractor.php` for additional data
- Update `src/Shopify/GraphQLClient.php` for custom Shopify logic

## License

This migration tool is provided as-is for your internal use.
