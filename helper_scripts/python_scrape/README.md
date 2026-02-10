# Nord.ro Doctor Scraper for Drupal 11

A comprehensive Python-based web scraping toolkit to extract doctor profiles from nord.ro and prepare them for import into Drupal 11.

## 📦 Package Contents

### Main Scripts

1. **`nord_doctor_scraper_final.py`** - ⭐ **START HERE**
   - Interactive CLI tool with menu-driven interface
   - Multiple input methods (URLs, HTML files, manual paste)
   - Real-time validation and parsing
   - Direct export to Drupal-compatible formats

2. **`advanced_scraper.py`**
   - Core parsing logic and data extraction
   - `DoctorProfileParser` - Parses individual doctor pages
   - `DoctorListingParser` - Extracts URLs from listing pages
   - Export functions for JSON and CSV

3. **`workflow.py`**
   - Batch processing workflow
   - Commands: extract-urls, parse-all, add-manual
   - Designed for processing multiple HTML files

4. **`scrape_doctors.py`**
   - Original scraper with requests library
   - Includes retry logic and rate limiting
   - Use when you have direct network access

### Helper Files

- **`batch_scrape.sh`** - Template bash script for automation
- **`generate_instructions.py`** - Generate workflow instructions
- **`DRUPAL_IMPORT_GUIDE.txt`** - Detailed Drupal import instructions
- **`test_parser.py`** - Test script for validation

## 🚀 Quick Start

### Method 1: Interactive Mode (Recommended)

```bash
python3 nord_doctor_scraper_final.py
```

Then follow the menu to:
1. Enter doctor URLs
2. Load URLs from file
3. Parse HTML files
4. Export to Drupal formats

### Method 2: See Usage Guide

```bash
python3 nord_doctor_scraper_final.py --guide
```

## 📋 Workflow Steps

### Step 1: Get Doctor URLs

**Option A: Manual Collection**
1. Visit: https://nord.ro/medici/?locatie=Pitești
2. Find all doctor profile links
3. Copy URLs (format: `https://nord.ro/medici/dr-name/`)

**Option B: Ask Claude**
```
"Can you search for all doctors at Nord Pitești and list their profile URLs?"
```

### Step 2: Fetch HTML Content

**Option A: Using Browser**
- Visit each doctor URL
- Right-click → "Save Page As" → save as HTML
- Save all to one folder

**Option B: Using Claude's web_fetch**
- For each URL, ask: `"Can you fetch [URL]"`
- Copy the HTML content to files

### Step 3: Parse the Data

```bash
python3 nord_doctor_scraper_final.py
# Choose option 3: Parse HTML files from directory
# Point to your HTML files folder
```

### Step 4: Export for Drupal

From the menu, choose option 6 to export.

Output files:
- `doctors.json` - Complete structured data
- `doctors_drupal.csv` - Ready for Drupal import

## 📊 Output Format

### CSV Columns
```
name, specialty, location, image_url, experience, education,
description, phone, email, source_url
```

### JSON Structure
```json
{
  "url": "https://nord.ro/medici/dr-name/",
  "name": "DR. NAME",
  "image_url": "https://nord.ro/uploads/image.jpg",
  "specialty": ["Specialty 1", "Specialty 2"],
  "location": "Pitești",
  "experience": ["2022 – Present: Position"],
  "education": ["2011: Degree, Institution"],
  "description": "Full biography text",
  "phone": "+40 XXX XXX XXX",
  "email": "doctor@nord.ro"
}
```

## 🔧 Requirements

```bash
pip install beautifulsoup4 requests --break-system-packages
```

Or for virtual environment:
```bash
python3 -m venv venv
source venv/bin/activate
pip install beautifulsoup4 requests
```

## 📥 Drupal 11 Import

### Method 1: Feeds Module

1. Install Feeds: `composer require drupal/feeds`
2. Enable: `drush en feeds`
3. Configure at: `/admin/structure/feeds`
4. Map CSV columns to your content type fields
5. Import at: `/import`

### Method 2: Migrate Module

Create migration YAML:
```yaml
id: nord_doctors
label: Import Nord Doctors
source:
  plugin: csv
  path: /path/to/doctors_drupal.csv
process:
  title: name
  field_specialty: specialty
  field_location: location
  # ... map other fields
destination:
  plugin: entity:node
  default_bundle: doctor
```

Run migration:
```bash
drush migrate:import nord_doctors
```

### Method 3: Custom PHP Script

Use the JSON output to create a custom Drush command or batch import script.

## 🖼️ Importing Images

Images are referenced by URL in the data. To import them:

1. **Download images first:**
```bash
wget -i image_urls.txt -P /path/to/drupal/sites/default/files/doctors/
```

2. **Create file entities in Drupal:**
```php
$file = File::create([
  'uri' => 'public://doctors/image.jpg',
  'status' => 1,
]);
$file->save();
```

3. **Associate with nodes:**
```php
$node->set('field_image', $file->id());
```

## 🎯 Example Usage

### Scrape a Single Doctor

```python
from advanced_scraper import DoctorProfileParser

with open('doctor.html', 'r') as f:
    html = f.read()

parser = DoctorProfileParser(html, "https://nord.ro/medici/dr-name/")
doctor_data = parser.parse()
print(doctor_data['name'])
```

### Batch Process Multiple Doctors

```bash
# Save all doctor HTMLs to html_profiles/ folder
python3 workflow.py parse-all
```

## 🔍 Validation

Test the parser with a sample:
```bash
python3 test_parser.py
```

## 📝 Notes

- **Rate Limiting**: When fetching pages, add delays (1-2 seconds) between requests
- **User Agent**: The scraper uses a standard browser user agent
- **Data Quality**: Always validate extracted data before importing
- **Images**: URLs are relative; they're converted to absolute URLs automatically
- **Encoding**: All output is UTF-8 encoded for Romanian characters

## 🐛 Troubleshooting

### "No doctors found"
- Check if the HTML file contains the full page source
- Try saving the page with "Save Page As" → "Webpage, Complete"

### "Parsing errors"
- The website structure may have changed
- Check `advanced_scraper.py` selectors and adjust regex patterns

### "Missing data fields"
- Some doctor profiles may not have all fields
- Fields will be `null` or `[]` if not found

## 💡 Tips

1. **Start Small**: Test with 1-2 doctors first
2. **Validate Data**: Check the JSON output before CSV export
3. **Backup**: Keep original HTML files as backup
4. **Drupal Content Type**: Create matching fields in Drupal before import
5. **Image Fields**: Set up image field in Drupal, then import URLs separately

## 📚 Additional Resources

- [BeautifulSoup Documentation](https://www.crummy.com/software/BeautifulSoup/bs4/doc/)
- [Drupal Feeds Module](https://www.drupal.org/project/feeds)
- [Drupal Migrate API](https://www.drupal.org/docs/drupal-apis/migrate-api)

## 🤝 Support

For issues or questions:
1. Check the DRUPAL_IMPORT_GUIDE.txt
2. Review the example HTML in test_parser.py
3. Validate your HTML structure matches expected format

## ⚖️ License

This tool is for personal/educational use. Always respect the website's terms of service and robots.txt when scraping.

---

**Version**: 1.0  
**Last Updated**: December 2024  
**Compatibility**: Python 3.7+, Drupal 11
