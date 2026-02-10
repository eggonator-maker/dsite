# 🚀 Quick Start Guide - Nord Doctor Scraper

## TL;DR - Get Started in 3 Steps

```bash
# 1. Run the interactive scraper
python3 nord_doctor_scraper_final.py

# 2. Follow the menu to add doctor URLs or HTML files

# 3. Export and import into Drupal
```

---

## 📦 What You Get

✅ **8 Python Scripts**
- Complete scraping solution
- Interactive and batch modes
- Drupal-ready output

✅ **Ready-to-Import Data**
- JSON format (structured data)
- CSV format (Drupal import)

✅ **Complete Documentation**
- README.md (overview)
- USAGE_EXAMPLE.md (step-by-step)
- DRUPAL_IMPORT_GUIDE.txt (Drupal specifics)

---

## 📋 Three Ways to Use

### 1️⃣ Quick Interactive Mode (Easiest)

```bash
python3 nord_doctor_scraper_final.py
```

**Best for**: Manual scraping, small batches, testing

### 2️⃣ Batch Processing Mode

```bash
python3 workflow.py parse-all
```

**Best for**: Large batches, automation, repeated runs

### 3️⃣ Custom Integration

```python
from advanced_scraper import DoctorProfileParser

# Your custom code here
```

**Best for**: Integration with existing systems

---

## 🎯 Typical Workflow

```
1. Get URLs          →  Browser or Claude
2. Fetch HTML        →  Save pages / web_fetch
3. Parse Data        →  Run scraper
4. Export            →  JSON + CSV
5. Import to Drupal  →  Feeds / Migrate / Custom
```

**Time**: ~2 hours for 50 doctors

---

## 🔧 Requirements

**System**:
- Python 3.7+
- BeautifulSoup4
- Requests (optional)

**Drupal**:
- Drupal 11
- Doctor content type
- Feeds OR Migrate module

---

## 📁 File Guide

| File | Purpose |
|------|---------|
| `nord_doctor_scraper_final.py` | ⭐ **START HERE** - Interactive CLI |
| `advanced_scraper.py` | Core parsing engine |
| `workflow.py` | Batch processing |
| `scrape_doctors.py` | Network scraping (if available) |
| `README.md` | Full documentation |
| `USAGE_EXAMPLE.md` | Complete example |
| `DEMO_*.json/csv` | Sample output |

---

## 🆘 Common Issues & Solutions

### "Can't find doctors"
➡️ Check HTML files have complete page source

### "Missing data fields"  
➡️ Website structure changed - update selectors

### "Import fails in Drupal"
➡️ Verify field names match, check Drupal logs

### "Romanian characters broken"
➡️ Ensure UTF-8 encoding throughout

---

## 💡 Pro Tips

1. **Test first**: Scrape 1-2 doctors before batch processing
2. **Save HTML**: Keep original files as backup
3. **Validate**: Check JSON before importing to Drupal
4. **Images**: Handle separately (URLs are in the data)
5. **Rate limit**: Add delays when scraping (be polite!)

---

## 📚 Documentation

```
README.md              → Overview & setup
USAGE_EXAMPLE.md       → Complete walkthrough  
DRUPAL_IMPORT_GUIDE.txt → Drupal-specific help
```

---

## 🎓 Learning Path

**Beginner**: Use `nord_doctor_scraper_final.py` interactive mode

**Intermediate**: Use `workflow.py` for batch processing

**Advanced**: Modify `advanced_scraper.py` for custom needs

---

## ✅ Checklist

Before importing to Drupal:

- [ ] All doctor URLs collected
- [ ] HTML files saved
- [ ] Scraper run successfully
- [ ] JSON validated
- [ ] CSV checked
- [ ] Drupal content type created
- [ ] Field mappings configured
- [ ] Test import with 1-2 doctors
- [ ] Full batch import

---

## 🤝 Need Help?

1. Check `README.md` for details
2. Read `USAGE_EXAMPLE.md` for step-by-step guide
3. Review `DRUPAL_IMPORT_GUIDE.txt` for Drupal specifics
4. Check the demo files (`DEMO_*.json`, `DEMO_*.csv`)

---

## 📊 Sample Output

**JSON Structure**:
```json
{
  "name": "DR. GUIȚĂ ALEXANDRU GIGEL",
  "specialty": ["ATI"],
  "experience": ["2022 – Present: ..."],
  "education": ["2011: ..."]
}
```

**CSV Format**:
```csv
name,specialty,location,experience,education,phone,email
```

---

## 🚀 Ready to Start?

```bash
# Quick start
python3 nord_doctor_scraper_final.py

# View guide
python3 nord_doctor_scraper_final.py --guide

# Batch mode
python3 workflow.py parse-all
```

---

**Version**: 1.0  
**Tested with**: Python 3.7+, Drupal 11  
**Last updated**: December 2024

Good luck! 🎉
