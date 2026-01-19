import json
import os
from bs4 import BeautifulSoup

# Configuration
INPUT_FILE = os.path.join("scraped_data", "doctors.json")
OUTPUT_FILE = os.path.join("scraped_data", "doctors_processed.json")

# List of fields that contain HTML to be cleaned
HTML_FIELDS = [
    "field_about_doctor",
    "field_competencies",
    "field_professional_experience",
    "field_education_training",
    "field_conferences_seminars",
    "field_awards_distinctions",
    "field_publications",
    "field_memberships"
]

def clean_html_content(html_string):
    """
    Removes wrapper divs (wpb_text_column, wpb_wrapper) and returns 
    the inner HTML content (paragraphs, lists, etc.).
    """
    if not html_string:
        return ""

    soup = BeautifulSoup(html_string, "html.parser")

    # The site structure typically nests content inside .wpb_wrapper
    # <div class="wpb_text_column ..."><div class="wpb_wrapper">...content...</div></div>
    wrapper = soup.find(class_="wpb_wrapper")
    
    if wrapper:
        # decode_contents() returns the string representation of the tag's children
        # This effectively strips the parent div but keeps <p>, <ul>, <br>, etc.
        return wrapper.decode_contents().strip()

    # Fallback: If .wpb_wrapper isn't found, try to unwrap specific container classes
    # if they exist at the top level
    found_containers = soup.find_all("div", class_=["wpb_text_column", "wpb_content_element"])
    if found_containers:
        for div in found_containers:
            div.unwrap()
        return str(soup).strip()

    # If no known wrappers are found, return original (or strip generic divs if needed)
    return str(soup).strip()

def main():
    if not os.path.exists(INPUT_FILE):
        print(f"Error: Input file '{INPUT_FILE}' not found. Run the scraper first.")
        return

    print(f"Reading from {INPUT_FILE}...")
    
    try:
        with open(INPUT_FILE, 'r', encoding='utf-8') as f:
            data = json.load(f)
    except json.JSONDecodeError:
        print("Error: Failed to decode JSON. Check if the file is valid.")
        return

    processed_count = 0
    
    for entry in data:
        for field in HTML_FIELDS:
            if field in entry and entry[field]:
                original_content = entry[field]
                cleaned_content = clean_html_content(original_content)
                
                # Update the entry
                entry[field] = cleaned_content
        
        processed_count += 1

    # Save to new file
    print(f"Processing complete. Saving {processed_count} records to {OUTPUT_FILE}...")
    
    # Ensure directory exists
    os.makedirs(os.path.dirname(OUTPUT_FILE), exist_ok=True)
    
    with open(OUTPUT_FILE, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=4)

    print("Done.")

if __name__ == "__main__":
    main()