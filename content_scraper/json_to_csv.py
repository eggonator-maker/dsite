import json
import csv
import os
from bs4 import BeautifulSoup

# Configuration
INPUT_JSON = os.path.join("scraped_data", "doctors_processed.json") # Or doctors_processed.json if you ran the cleaner
OUTPUT_CSV = os.path.join("scraped_data", "doctors_import.csv")

# Fields to export to CSV (Column Headers)
CSV_HEADERS = [
    "title",
    "field_primary_speciality",
    "field_image", # Will store relative path: images/dr-name/original.jpg
    "field_about_doctor",
    "field_competencies",
    "field_professional_experience",
    "field_education_training",
    "field_conferences_seminars",
    "field_awards_distinctions",
    "field_publications",
    "field_memberships",
    "field_original_url" # Using the 'url' field as a unique ID
]

def clean_html_content(html_string):
    """
    Same cleaning logic as before: removes wrapper divs, keeps inner HTML.
    """
    if not html_string:
        return ""

    soup = BeautifulSoup(html_string, "html.parser")
    wrapper = soup.find(class_="wpb_wrapper")
    
    if wrapper:
        return wrapper.decode_contents().strip()

    # Fallback: unwrap generic containers
    found_containers = soup.find_all("div", class_=["wpb_text_column", "wpb_content_element"])
    if found_containers:
        for div in found_containers:
            div.unwrap()
        return str(soup).strip()

    return str(soup).strip()

def main():
    if not os.path.exists(INPUT_JSON):
        print(f"Error: Input file '{INPUT_JSON}' not found.")
        return

    print(f"Reading {INPUT_JSON}...")
    
    try:
        with open(INPUT_JSON, 'r', encoding='utf-8') as f:
            data = json.load(f)
    except json.JSONDecodeError:
        print("Error decoding JSON.")
        return

    print(f"Converting {len(data)} records to CSV...")

    with open(OUTPUT_CSV, 'w', encoding='utf-8', newline='') as csvfile:
        writer = csv.DictWriter(csvfile, fieldnames=CSV_HEADERS)
        writer.writeheader()

        for entry in data:
            row = {}
            
            # 1. Basic Text Fields
            row["title"] = entry.get("title", "")
            row["field_primary_speciality"] = entry.get("field_primary_speciality", "")
            row["field_original_url"] = entry.get("url", "")

            # 2. Image Field
            # The JSON has: "field_image": { "original": "path/to/img.jpg", ... }
            # We just want the path string for the CSV.
            image_data = entry.get("field_image", {})
            if isinstance(image_data, dict) and "original" in image_data:
                row["field_image"] = image_data["original"]
            elif isinstance(image_data, str):
                row["field_image"] = image_data # In case it was already a string
            else:
                row["field_image"] = ""

            # 3. HTML Fields (Clean them while we are at it)
            html_fields_map = {
                "field_about_doctor": "field_about_doctor",
                "field_competencies": "field_competencies",
                "field_professional_experience": "field_professional_experience",
                "field_education_training": "field_education_training",
                "field_conferences_seminars": "field_conferences_seminars",
                "field_awards_distinctions": "field_awards_distinctions",
                "field_publications": "field_publications",
                "field_memberships": "field_memberships"
            }

            for csv_col, json_key in html_fields_map.items():
                raw_html = entry.get(json_key, "")
                row[csv_col] = clean_html_content(raw_html)

            writer.writerow(row)

    print(f"Successfully created {OUTPUT_CSV}")

if __name__ == "__main__":
    main()