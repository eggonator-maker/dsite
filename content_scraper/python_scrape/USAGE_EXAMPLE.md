# Complete Usage Example - Nord Doctor Scraper

## Scenario: Scraping All Doctors from Pitești

This document walks through a complete real-world example of scraping all doctors from the Nord Pitești location and importing them into Drupal 11.

---

## Phase 1: Preparation (5 minutes)

### 1.1 Install Dependencies

```bash
pip install beautifulsoup4 requests --break-system-packages
```

### 1.2 Organize Your Workspace

```bash
mkdir -p ~/nord_scraping
cd ~/nord_scraping
# Copy all .py files here
```

---

## Phase 2: Get Doctor URLs (10 minutes)

### Method A: Using Browser

1. Visit https://nord.ro/medici/?locatie=Pitești
2. Open DevTools (F12) → Console
3. Run this JavaScript:

```javascript
// Extract all doctor URLs
const links = Array.from(document.querySelectorAll('a[href*="/medici/"]'))
  .map(a => a.href)
  .filter(href => href.match(/\/medici\/[^/]+\/?$/))
  .filter((v, i, a) => a.indexOf(v) === i); // unique only

console.log(links.join('\n'));
```

4. Copy the output

### Method B: Using Claude

Ask Claude:
```
"Can you search for all doctors at Nord Pitești and extract their profile URLs?"
```

### 2.1 Save URLs to File

Create `doctor_urls.txt`:
```
https://nord.ro/medici/dr-guita-alexandru-gigel/
https://nord.ro/medici/dr-popescu-maria/
https://nord.ro/medici/dr-ionescu-ion/
... (add all URLs)
```

---

## Phase 3: Fetch HTML Content (20-30 minutes)

### Method A: Using Browser (Recommended for small batches)

For each URL in `doctor_urls.txt`:

1. Visit the URL
2. Right-click → "Save Page As..."
3. Choose "Webpage, HTML Only"
4. Save as `doctor_1.html`, `doctor_2.html`, etc. in `html_profiles/` folder

### Method B: Using wget/curl (if network access available)

```bash
mkdir html_profiles
i=1
while IFS= read -r url; do
    echo "Fetching doctor $i: $url"
    curl -s "$url" > "html_profiles/doctor_${i}.html"
    sleep 2  # Be polite to the server
    i=$((i+1))
done < doctor_urls.txt
```

### Method C: Using Claude's web_fetch

For each URL, ask Claude:
```
"Can you fetch https://nord.ro/medici/dr-name/ and save the HTML?"
```

Then save each response to a file.

---

## Phase 4: Parse and Extract Data (5 minutes)

### Option 1: Using Interactive Script (Easiest)

```bash
python3 nord_doctor_scraper_final.py
```

Then:
1. Choose option `3` (Parse HTML files from directory)
2. Enter path: `html_profiles`
3. Wait for parsing to complete
4. Choose option `6` (Export to JSON and CSV)

### Option 2: Using Workflow Script

```bash
# Parse all HTML files
python3 workflow.py parse-all

# Output will be in /home/claude/doctor_data/
```

### Option 3: Custom Python Script

```python
from advanced_scraper import DoctorProfileParser, save_to_json, save_to_drupal_csv
from pathlib import Path

doctors = []
html_dir = Path('html_profiles')

for html_file in sorted(html_dir.glob('*.html')):
    with open(html_file, 'r', encoding='utf-8') as f:
        html_content = f.read()
    
    # You'll need to match URLs with files
    url = f"https://nord.ro/medici/{html_file.stem}/"
    
    parser = DoctorProfileParser(html_content, url)
    doctor = parser.parse()
    doctors.append(doctor)
    print(f"✓ Parsed: {doctor['name']}")

# Export
save_to_json(doctors, 'all_doctors.json')
save_to_drupal_csv(doctors, 'drupal_import.csv')
print(f"\n✅ Scraped {len(doctors)} doctors!")
```

---

## Phase 5: Validate the Data (5 minutes)

### 5.1 Check JSON Output

```bash
# View first doctor
python3 << EOF
import json
with open('all_doctors.json', 'r') as f:
    doctors = json.load(f)
print(json.dumps(doctors[0], indent=2, ensure_ascii=False))
EOF
```

### 5.2 Check CSV Output

```bash
# View CSV in readable format
column -s, -t drupal_import.csv | less -S
```

### 5.3 Verify Data Quality

Check for:
- [ ] All names extracted correctly
- [ ] Specialties are present
- [ ] Experience and education lists are populated
- [ ] Image URLs are valid (if present)
- [ ] No encoding issues (Romanian characters display correctly)

---

## Phase 6: Prepare for Drupal Import (15 minutes)

### 6.1 Create Doctor Content Type

In Drupal admin (`/admin/structure/types/add`):

**Content Type: Doctor**

Fields:
```
- Title (built-in) → Will hold doctor's name
- field_specialty (Text, plain)
- field_location (Text, plain)
- field_image (Image)
- field_experience (Text, long)
- field_education (Text, long)
- field_description (Text, long, formatted)
- field_phone (Telephone)
- field_email (Email)
- field_source_url (Link)
```

### 6.2 Download Images (Optional)

If doctors have profile images:

```bash
# Extract image URLs
python3 << EOF
import json
with open('all_doctors.json', 'r') as f:
    doctors = json.load(f)

with open('image_urls.txt', 'w') as f:
    for d in doctors:
        if d.get('image_url'):
            f.write(d['image_url'] + '\n')
EOF

# Download images
mkdir doctor_images
cd doctor_images
wget -i ../image_urls.txt
cd ..
```

Then upload to Drupal: `sites/default/files/doctor_images/`

---

## Phase 7: Import into Drupal (15-20 minutes)

### Method 1: Using Feeds Module

#### Install Feeds
```bash
composer require drupal/feeds
drush en feeds -y
```

#### Configure Feed Type

1. Go to `/admin/structure/feeds`
2. Click "Add feed type"
3. Configure:
   - **Label**: Nord Doctors Import
   - **Parser**: CSV
   - **Fetcher**: Upload
   - **Processor**: Node

4. Configure Mappings:
```
CSV Column          →  Drupal Field
─────────────────      ──────────────────
name                →  title
specialty           →  field_specialty
location            →  field_location
experience          →  field_experience
education           →  field_education
description         →  field_description
phone               →  field_phone
email               →  field_email
source_url          →  field_source_url
```

5. Save configuration

#### Import Data

1. Go to `/import`
2. Choose "Nord Doctors Import"
3. Upload `drupal_import.csv`
4. Click "Import"
5. Wait for completion

### Method 2: Using Migrate Module

#### Create Migration Config

Create `config/migrate_plus.migration.nord_doctors.yml`:

```yaml
id: nord_doctors
label: Import Nord Doctors
migration_group: nord_content

source:
  plugin: csv
  path: '/path/to/drupal_import.csv'
  header_row_count: 1
  keys:
    - name

process:
  type:
    plugin: default_value
    default_value: doctor
  
  title: name
  field_specialty: specialty
  field_location: location
  field_experience: experience
  field_education: education
  field_description: description
  field_phone: phone
  field_email: email
  field_source_url: source_url
  
  uid:
    plugin: default_value
    default_value: 1

destination:
  plugin: entity:node
```

#### Run Migration

```bash
drush migrate:import nord_doctors
```

### Method 3: Custom Drush Command

Create a custom module with:

```php
<?php
// Custom import script
use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;

$json = file_get_contents('/path/to/all_doctors.json');
$doctors = json_decode($json, TRUE);

foreach ($doctors as $doctor) {
  $node = Node::create([
    'type' => 'doctor',
    'title' => $doctor['name'],
    'field_specialty' => $doctor['specialty'] ? implode(', ', $doctor['specialty']) : '',
    'field_location' => $doctor['location'],
    'field_experience' => $doctor['experience'] ? implode("\n", $doctor['experience']) : '',
    'field_education' => $doctor['education'] ? implode("\n", $doctor['education']) : '',
    'field_description' => $doctor['description'],
    'field_phone' => $doctor['phone'],
    'field_email' => $doctor['email'],
    'field_source_url' => ['uri' => $doctor['url']],
    'status' => 1,
  ]);
  
  $node->save();
  echo "Imported: " . $doctor['name'] . "\n";
}
```

---

## Phase 8: Verify Import (5 minutes)

### 8.1 Check Content

1. Go to `/admin/content`
2. Filter by "Doctor" content type
3. Verify all doctors are imported

### 8.2 Check Sample Doctor

1. View a few doctor nodes
2. Verify all fields are populated correctly
3. Check for formatting issues

### 8.3 Create Views

Create a view to display doctors:
```
Path: /doctors
Display: List of doctor cards
Fields: Image, Name, Specialty, Location
Filters: Published = Yes
Sort: Name (ascending)
```

---

## Troubleshooting

### Missing Data

**Problem**: Some fields are empty
**Solution**: 
- Check if the HTML structure changed
- Update selectors in `advanced_scraper.py`
- Re-parse the HTML files

### Encoding Issues

**Problem**: Romanian characters display incorrectly
**Solution**:
- Ensure files are UTF-8 encoded
- Use `--default-character-set=utf8mb4` in MySQL
- Check Drupal database encoding

### Import Fails

**Problem**: Feeds/Migrate import errors
**Solution**:
- Check Drupal logs: `/admin/reports/dblog`
- Verify CSV format (no extra quotes)
- Check field machine names match

### Missing Images

**Problem**: Images don't display
**Solution**:
1. Download images separately
2. Upload to Drupal file system
3. Use image import module or custom code

---

## Post-Import Tasks

1. **Create URL aliases**: `/admin/config/search/path/patterns`
   - Pattern: `/doctors/[node:title]`

2. **Configure display**: `/admin/structure/types/manage/doctor/display`
   - Arrange fields
   - Choose formatters

3. **Add menu link**: Add "Doctors" to main menu

4. **Create search**: Install Search API, index doctors

5. **Add filters**: Create views with exposed filters for specialty, location

---

## Maintenance

### Re-scraping Updates

To update doctor information:

1. Re-fetch HTML files for changed doctors
2. Re-run the parser
3. Import again (Feeds can update existing nodes)

### Scheduling

Set up a cron job to periodically check for updates:

```bash
0 2 * * * cd /path/to/scraper && python3 scrape_doctors.py
```

---

## Summary

**Time Investment**:
- Initial setup: 30 minutes
- Scraping 50 doctors: 1 hour
- Drupal import: 30 minutes
- **Total**: ~2 hours

**Results**:
- Complete doctor profiles in Drupal
- Structured, searchable data
- Easy to maintain and update
- Professional presentation

**Next Steps**:
- Customize doctor page templates
- Add appointment booking system
- Create specialty-based landing pages
- Add doctor ratings/reviews

---

## Resources

- **Scraper files**: All Python scripts
- **Drupal modules**: Feeds, Migrate, Pathauto
- **Documentation**: README.md, DRUPAL_IMPORT_GUIDE.txt

Good luck with your Drupal 11 website! 🚀
