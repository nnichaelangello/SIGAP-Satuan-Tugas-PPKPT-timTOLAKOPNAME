# Dokumentasi Sistem SIGAP PPKPT

## 1. Entity Relationship Diagram (ERD)

Diagram ini menggambarkan struktur database dan relasi antar tabel dalam sistem.

```mermaid
erDiagram
    Admin ||--o{ Laporan : "validates"
    Admin ||--o{ JadwalPertemuan : "schedules"
    Psikolog ||--o{ JadwalPertemuan : "assigned to"
    Psikolog ||--o{ CatatanKonsultasi : "writes"
    Laporan ||--o{ JadwalPertemuan : "has"
    Laporan ||--o{ CatatanKonsultasi : "has"
    Laporan ||--o{ FeedbackUser : "has"
    Laporan ||--o{ StatusHistory : "tracks"
    Laporan ||--o{ Bukti : "has evidence"
    Laporan ||--o{ BlockchainLog : "immutable log"

    Admin {
        int id PK
        string username UK
        string email UK
        string password_hash
        string nama
        datetime locked_until
        int failed_attempts
    }

    Psikolog {
        int id PK
        string email UK
        string password_hash
        string nama_lengkap
        string spesialisasi
        string no_telepon
        enum status
    }

    Laporan {
        int id PK
        string kode_pelaporan UK
        enum status_laporan
        string status_darurat
        string detail_kejadian
        string lokasi_kejadian
        string email_korban
        int assigned_psikolog_id FK
        timestamp created_at
    }

    JadwalPertemuan {
        int id PK
        int laporan_id FK
        int psikolog_id FK
        datetime waktu_mulai
        datetime waktu_selesai
        enum tipe "online/offline"
        string link_atau_tempat
        enum status_jadwal
    }

    CatatanKonsultasi {
        int id PK
        int laporan_id FK
        int psikolog_id FK
        text ringkasan_kasus
        text detail_konsultasi
        text rekomendasi
        enum tingkat_risiko
        enum status_catatan
    }

    FeedbackUser {
        int id PK
        int laporan_id FK
        enum tipe_feedback "confirm/dispute"
        text komentar
        text detail_dispute
    }

    StatusHistory {
        int id PK
        int laporan_id FK
        string status_lama
        string status_baru
        string diubah_oleh_role
        text perubahan_data "JSON Diff"
    }

    Bukti {
        int id PK
        int laporan_id FK
        string file_url
        string file_type
    }
```

---

## 2. Use Case Diagram

Diagram ini menggambarkan interaksi aktor dengan fitur-fitur sistem.

```mermaid
usecaseDiagram
    actor "Masyarakat (User)" as User
    actor "Administrator" as Admin
    actor "Psikolog" as Psikolog
    actor "Blockchain System" as Chain

    package "SIGAP PPKPT System" {
        usecase "Melapor Kekerasan" as UC1
        usecase "Monitoring Status (Tracking)" as UC2
        usecase "Upload Bukti" as UC3
        usecase "Konfirmasi/Dispute Hasil" as UC4
        
        usecase "Validasi Laporan" as UC5
        usecase "Penjadwalan Konsultasi" as UC6
        usecase "Manajemen User" as UC7
        
        usecase "Input Catatan Konsultasi" as UC8
        usecase "Lihat Jadwal & Penugasan" as UC9
        
        usecase "Log Audit Trail" as UC10
    }

    User --> UC1
    User --> UC2
    User --> UC3
    User --> UC4

    Admin --> UC5
    Admin --> UC6
    Admin --> UC7
    Admin --> UC2 : "View All"

    Psikolog --> UC8
    Psikolog --> UC9
    Psikolog --> UC2 : "View Assigned"

    UC1 ..> UC10 : <<include>>
    UC5 ..> UC10 : <<include>>
    UC8 ..> UC10 : <<include>>
    UC4 ..> UC10 : <<include>>

    UC10 --> Chain : "Record Hash"
```

---

## 3. Website Flow Diagram (Process Flow)

Alur proses dari pelaporan hingga penyelesaian kasus.

```mermaid
graph TD
    Start([User Landing Page]) -->|Klik Lapor| Form[Form Pelaporan]
    Form -->|Upload Bukti & Submit| API_Submit{Validasi Data?}
    API_Submit -->|Invalid| Form
    API_Submit -->|Valid| DB_Save[(Database MySQL)]
    DB_Save --> Chain_Log[[Log to Blockchain]]
    DB_Save --> Success[Halaman Sukses & Kode Tracking]

    Success --> Monitor[Halaman Monitoring]
    
    subgraph "Phase 1: Validasi Admin"
        DB_Save --> Admin_List[Admin Dashboard]
        Admin_List -->|Review| Validate{Valid?}
        Validate -->|Tolak| Status_Tolak[Status: Ditolak]
        Validate -->|Terima| Status_Lanjut[Status: Dilanjutkan]
        Status_Tolak --> Chain_Log
        Status_Lanjut --> Chain_Log
    end

    subgraph "Phase 2 & 3: Penjadwalan & Konsultasi"
        Status_Lanjut --> Schedule[Admin Atur Jadwal]
        Schedule --> Status_Jadwal[Status: Dijadwalkan]
        Status_Jadwal --> Psikolog_Dash[Psikolog Dashboard]
        Psikolog_Dash -->|Konsultasi Selesai| Input_Note[Input Catatan Konsultasi]
        Input_Note -->|Submit| Status_Confirm[Status: Menunggu Konfirmasi]
        Status_Confirm --> Chain_Log
    end

    subgraph "Phase 4: Konfirmasi User"
        Status_Confirm --> Monitor
        Monitor -->|User Review| User_Action{Setuju?}
        User_Action -->|Ya| Status_Closed[Status: Closed]
        User_Action -->|Tidak| Status_Dispute[Status: Dispute]
        Status_Closed --> Chain_Log
        Status_Dispute --> Chain_Log
        Status_Dispute --> Psikolog_Dash
    end

    Status_Closed --> End([Selesai])
```
