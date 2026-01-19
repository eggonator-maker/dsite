import csv
import os
import re
import unicodedata
from bs4 import BeautifulSoup

# Configuration
INPUT_CSV = os.path.join("doctors_edited.csv")
OUTPUT_CSV = os.path.join("scraped_data", "doctors_import_new.csv")

# Fields to export to CSV
CSV_HEADERS = [
    "title",
    "field_primary_speciality",
    "field_image",
    "field_about_doctor",
    "field_competencies",
    "field_professional_experience",
    "field_education_training",
    "field_conferences_seminars",
    "field_awards_distinctions",
    "field_publications",
    "field_memberships",
    "field_original_url",
    "centralizator_specialitate_normalized"
]

def clean_html_content(html_string):
    """Strips wrapper divs, keeps inner HTML"""
    if not html_string:
        return ""
    soup = BeautifulSoup(html_string, "html.parser")
    wrapper = soup.find(class_="wpb_wrapper")
    if wrapper:
        return wrapper.decode_contents().strip()
    found_containers = soup.find_all("div", class_=["wpb_text_column", "wpb_content_element"])
    if found_containers:
        for div in found_containers:
            div.unwrap()
        return str(soup).strip()
    return str(soup).strip()

def main():
    if not os.path.exists(INPUT_CSV):
        print(f"Error: Input file '{INPUT_CSV}' not found.")
        return

    print(f"Reading {INPUT_CSV}...")
    
    with open(INPUT_CSV, 'r', encoding='utf-8') as infile:
        reader = csv.DictReader(infile)
        data = list(reader)
    
    print(f"Processing {len(data)} records...")
    
    with open(OUTPUT_CSV, 'w', encoding='utf-8', newline='') as csvfile:
        writer = csv.DictWriter(csvfile, fieldnames=CSV_HEADERS)
        writer.writeheader()
        
        for entry in data:
            row = {}
            doctor_name = entry.get("title", "doctor")
            
            # Title case conversion
            d_n = " ".join(word.capitalize() for word in doctor_name.split())
            
            row["title"] = d_n
            row["field_primary_speciality"] = entry.get("field_primary_speciality", "")
            row["field_original_url"] = entry.get("url", "")
            row["field_image"] = entry.get("field_image", "")
            
            # Clean HTML fields
            html_fields = [
                "field_about_doctor",
                "field_competencies",
                "field_professional_experience",
                "field_education_training",
                "field_conferences_seminars",
                "field_awards_distinctions",
                "field_publications",
                "field_memberships"
            ]
            
            for field in html_fields:
                row[field] = clean_html_content(entry.get(field, ""))
            
            writer.writerow(row)
    
    print(f"Done! CSV created at: {OUTPUT_CSV}")

if __name__ == "__main__":
    main()