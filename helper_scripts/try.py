import json
import csv
import os
from bs4 import BeautifulSoup

# Configuration
INPUT_CSV = os.path.join("doctors_edited.csv")
OUTPUT_JSON = os.path.join("scraped_data", "doctors_processed_2.json")

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
    
    print(f"Processing {len(data)} records to JSON...")
    
    json_data = []
    
    for entry in data:
        record = {}
        
        # Basic fields
        doctor_name = entry.get("title", "doctor")
        record["title"] = " ".join(word.capitalize() for word in doctor_name.split())
        record["field_primary_speciality"] = entry.get("field_primary_speciality", "")
        record["url"] = entry.get("url", "")
        record["field_image"] = entry.get("field_image", "")
        record["centralizator_specialitate_normalized"] = entry.get("centralizator_specialitate_normalized", "")
        
        # HTML fields - clean them
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
            record[field] = clean_html_content(entry.get(field, ""))
        
        json_data.append(record)
    
    # Write JSON
    with open(OUTPUT_JSON, 'w', encoding='utf-8') as outfile:
        json.dump(json_data, outfile, ensure_ascii=False, indent=2)
    
    print(f"Successfully created {OUTPUT_JSON}")

if __name__ == "__main__":
    main()