import os
import time
import json
import requests
import pandas as pd
from pathlib import Path
from collections import defaultdict

BASE = "https://medapi.ro"
DOCTORS_URL = f"{BASE}/sync/doctor-muntenia"
DETAILS_URL = f"{BASE}/sync/doctor-muntenia/details"
LAB_URL = f"{BASE}/sync/lab-muntenia"

TOKEN = os.getenv("MEDAPI_TOKEN", "").strip()

HEADERS = {"Accept": "application/json"}
if TOKEN:
    HEADERS["Authorization"] = f"Bearer {TOKEN}"


def get_doctors():
    print("Fetching doctors list...")
    r = requests.get(DOCTORS_URL, headers=HEADERS, timeout=60)
    r.raise_for_status()
    js = r.json()

    doctors_raw = js.get("data", [])
    doctors = []
    for row in doctors_raw:
        if not row or len(row) < 2:
            continue
        doctors.append({
            "DoctorId": int(row[0]),
            "DoctorName": row[1],
            "DoctorTitle": row[2] if len(row) > 2 else None
        })
    print(f"Found {len(doctors)} doctors")
    return doctors


def to_float_price(x):
    try:
        return float(x)
    except:
        return None


def get_doctor_services(doctor_id: int):
    payload = {"DoctorId": doctor_id}
    r = requests.post(DETAILS_URL, headers={**HEADERS, "Content-Type": "application/json"}, json=payload, timeout=60)
    r.raise_for_status()
    js = r.json()

    if not js.get("isValid", False):
        return []

    rows = []
    for entry in js.get("data", []):
        unit_id = entry.get("MedicalUnitId")
        unit_name = entry.get("MedicalUnitName")
        spec_name = entry.get("SpecialtyName")
        spec_id = entry.get("SpecialtyId")

        for s in entry.get("Services", []) or []:
            rows.append({
                "MedicalUnitName": unit_name,
                "MedicalUnitId": unit_id,
                "SpecialtyName": spec_name,
                "SpecialtyId": spec_id,
                "ServiceId": s.get("ServiceId"),
                "ServiceName": s.get("ServiceName"),
                "Price": to_float_price(s.get("Price")),
            })
    return rows


def get_lab_services():
    print("\nFetching lab services...")
    r = requests.get(LAB_URL, headers=HEADERS, timeout=60)
    r.raise_for_status()
    js = r.json()

    rows = []
    for lab in js.get("data", []):
        lab_id = lab.get("LaboratoryId")
        lab_name = lab.get("LaboratoryName")

        for s in lab.get("Services", []) or []:
            rows.append({
                "LaboratoryId": lab_id,
                "LaboratoryName": lab_name,
                "ServiceGroup": s.get("ServiceGroup"),
                "ServiceId": s.get("ServiceId"),
                "ServiceName": s.get("ServiceName"),
                "Price": to_float_price(s.get("Price")),
            })
    print(f"Lab services fetched: {len(rows)} rows")
    return rows


def restructure_doctors_by_specialty(df_doctors_services):
    """
    Restructure: Specialty -> Service (ServiceGroup) -> Doctors list with prices
    Compatible with Drupal paragraph structure
    """
    print("\nRestructuring doctor services by specialty...")
    
    structure = defaultdict(lambda: defaultdict(list))
    
    for _, row in df_doctors_services.iterrows():
        specialty = row['specialitate'] or 'Unknown Specialty'
        service = row['interventie'] or 'Unknown Service'
        
        structure[specialty][service].append({
            "item_name": row['medic'],
            "doctor_id": int(row['medic_id']) if pd.notna(row['medic_id']) else None,
            "service_id": row['service_id'],
            "price": row['pret'],
            "unit_name": row['unitate'],
        })
    
    # Convert to clean structure
    output = []
    for specialty, services in sorted(structure.items()):
        specialty_data = {
            "main_category": "Ambulatoriu",  # Can be customized based on specialty
            "subcategory": specialty,
            "service_groups": []
        }
        
        for service_name, doctors in sorted(services.items()):
            service_group = {
                "service_name": service_name,
                "items": doctors
            }
            specialty_data["service_groups"].append(service_group)
        
        output.append(specialty_data)
    
    print(f"Created {len(output)} specialty groups")
    return output


def restructure_lab_by_laboratory(df_lab):
    """
    Restructure: Laboratory -> ServiceGroup -> Services
    Compatible with Drupal paragraph structure
    """
    print("\nRestructuring lab services by laboratory...")
    
    structure = defaultdict(lambda: defaultdict(list))
    
    for _, row in df_lab.iterrows():
        lab_name = row['LaboratoryName'] or 'Unknown Laboratory'
        service_group = row['ServiceGroup'] or 'Unknown Group'
        
        structure[lab_name][service_group].append({
            "item_name": row['ServiceName'],
            "service_id": row['ServiceId'],
            "laboratory_id": row['LaboratoryId'],
            "price": row['Price']
        })
    
    # Convert to clean structure
    output = []
    for lab_name, service_groups in sorted(structure.items()):
        lab_data = {
            "main_category": "Laborator",
            "subcategory": lab_name,
            "service_groups": []
        }
        
        for group_name, services in sorted(service_groups.items()):
            service_group = {
                "service_name": group_name,
                "items": services
            }
            lab_data["service_groups"].append(service_group)
        
        output.append(lab_data)
    
    print(f"Created {len(output)} laboratory groups")
    return output


def export_to_csv(unified_structure, output_dir):
    """
    Export unified price structure to a flat CSV file for import into Drupal
    via the price_importer module.

    Columns:
        main_category, subcategory, service_group, display_name, price, appointment_url
    """
    rows = []
    for entry in unified_structure.get("main_categories", []):
        main_cat = entry.get("main_category", "")
        subcategory = entry.get("subcategory", "")
        for service_group in entry.get("service_groups", []):
            service_group_name = service_group.get("service_name", "")
            for item in service_group.get("items", []):
                rows.append({
                    "main_category": main_cat,
                    "subcategory": subcategory,
                    "service_group": service_group_name,
                    "display_name": item.get("item_name", ""),
                    "price": item.get("price", ""),
                    "appointment_url": "",
                })

    df = pd.DataFrame(
        rows,
        columns=["main_category", "subcategory", "service_group", "display_name", "price", "appointment_url"],
    )
    csv_path = output_dir / "drupal_prices_import.csv"
    df.to_csv(csv_path, index=False, encoding="utf-8")
    print(f"CSV rows written: {len(df)}")
    return csv_path


def main():
    # Create output directory
    output_dir = Path("medical_data_export")
    output_dir.mkdir(exist_ok=True)
    
    # Scrape doctors list
    doctors = get_doctors()
    df_doctors_list = pd.DataFrame(doctors)
    
    # Scrape doctor services with progress
    print("\nFetching doctor services...")
    all_rows = []
    total = len(doctors)
    
    for i, d in enumerate(doctors, start=1):
        doc_id = d["DoctorId"]
        doc_name = d["DoctorName"]
        
        if i % 10 == 0 or i == total:
            print(f"Progress: {i}/{total} ({i*100//total}%)")

        try:
            services = get_doctor_services(doc_id)
        except requests.HTTPError as e:
            print(f"  Doctor {doc_id} -> HTTP error: {e}")
            continue
        except Exception as e:
            print(f"  Doctor {doc_id} -> Error: {e}")
            continue

        for s in services:
            all_rows.append({
                "medic": doc_name,
                "medic_id": doc_id,
                "interventie": s["ServiceName"],
                "pret": s["Price"],
                "unitate": s["MedicalUnitName"],
                "specialitate": s["SpecialtyName"],
                "service_id": s["ServiceId"],
            })

        time.sleep(0.1)

    df_doctors_services = pd.DataFrame(all_rows)

    # Scrape lab services
    try:
        lab_rows = get_lab_services()
        df_lab = pd.DataFrame(lab_rows)
    except Exception as e:
        print(f"Error fetching lab services: {e}")
        df_lab = pd.DataFrame()

    # Sort data
    if not df_doctors_services.empty:
        df_doctors_services.sort_values(["medic", "interventie"], inplace=True, na_position="last")

    # Export XLSX
    print("\nExporting XLSX...")
    out_file = output_dir / "medici_interventii_pret.xlsx"
    with pd.ExcelWriter(out_file, engine="openpyxl") as writer:
        if not df_doctors_services.empty:
            df_doctors_services.to_excel(writer, sheet_name="medic-interventie-pret", index=False)
        if not df_lab.empty:
            df_lab.to_excel(writer, sheet_name="laborator-servicii", index=False)
        if not df_doctors_list.empty:
            df_doctors_list.to_excel(writer, sheet_name="lista-medici", index=False)

    # Create restructured JSON files
    print("\nCreating JSON exports...")
    
    # Export doctors list as JSON
    if not df_doctors_list.empty:
        doctors_json = df_doctors_list.to_dict('records')
        with open(output_dir / "doctors_list.json", 'w', encoding='utf-8') as f:
            json.dump(doctors_json, f, ensure_ascii=False, indent=2)
    
    # Export doctor services flat JSON
    if not df_doctors_services.empty:
        doctors_services_json = df_doctors_services.to_dict('records')
        with open(output_dir / "doctors_services_flat.json", 'w', encoding='utf-8') as f:
            json.dump(doctors_services_json, f, ensure_ascii=False, indent=2)
    
    # Export lab services flat JSON
    if not df_lab.empty:
        lab_json = df_lab.to_dict('records')
        with open(output_dir / "lab_services_flat.json", 'w', encoding='utf-8') as f:
            json.dump(lab_json, f, ensure_ascii=False, indent=2)
    
    # Create Drupal-compatible structured JSONs
    if not df_doctors_services.empty:
        doctors_structured = restructure_doctors_by_specialty(df_doctors_services)
        with open(output_dir / "doctors_services_structured.json", 'w', encoding='utf-8') as f:
            json.dump(doctors_structured, f, ensure_ascii=False, indent=2)
    
    if not df_lab.empty:
        lab_structured = restructure_lab_by_laboratory(df_lab)
        with open(output_dir / "lab_services_structured.json", 'w', encoding='utf-8') as f:
            json.dump(lab_structured, f, ensure_ascii=False, indent=2)
    
    # Create unified structure for Drupal ingestion
    unified_structure = {
        "main_categories": []
    }
    
    if not df_lab.empty:
        lab_structured = restructure_lab_by_laboratory(df_lab)
        unified_structure["main_categories"].extend(lab_structured)
    
    if not df_doctors_services.empty:
        doctors_structured = restructure_doctors_by_specialty(df_doctors_services)
        unified_structure["main_categories"].extend(doctors_structured)
    
    with open(output_dir / "drupal_prices_structure.json", 'w', encoding='utf-8') as f:
        json.dump(unified_structure, f, ensure_ascii=False, indent=2)

    # Export flat CSV for Drupal price_importer module
    csv_path = export_to_csv(unified_structure, output_dir)

    print(f"\n{'='*50}")
    print(f"Export complete: {output_dir}/")
    print(f"{'='*50}")
    print(f"XLSX file: medici_interventii_pret.xlsx")
    print(f"  - Medici servicii: {len(df_doctors_services)} rows")
    print(f"  - Laborator: {len(df_lab)} rows")
    print(f"  - Lista medici: {len(df_doctors_list)} doctors")
    print(f"\nJSON files:")
    print(f"  - doctors_list.json")
    print(f"  - doctors_services_flat.json")
    print(f"  - lab_services_flat.json")
    print(f"  - doctors_services_structured.json (Drupal-ready)")
    print(f"  - lab_services_structured.json (Drupal-ready)")
    print(f"  - drupal_prices_structure.json (Unified for import)")
    print(f"\nCSV file (for Drupal price_importer module):")
    print(f"  - drupal_prices_import.csv")


if __name__ == "__main__":
    main()