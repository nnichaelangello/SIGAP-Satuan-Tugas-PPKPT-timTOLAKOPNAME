// SPDX-License-Identifier: MIT
pragma solidity 0.8.19;

/**
 * @title LaporanChain
 * @dev Smart Contract untuk mencatat audit trail pelaporan kekerasan seksual (SIGAP PPKPT).
 *      Mencatat hash data untuk integritas, tanpa menyimpan data sensitif secara plain text.
 */
contract LaporanChain {
    
    // Struktur Data Audit
    struct AuditLog {
        uint256 id;
        string reportCode;      // Kode Laporan (e.g. PPKPT123456)
        string actionType;      // CREATE, UPDATE_ADMIN, NOTE_PSIKOLOG, FEEDBACK, DISPUTE
        string dataHash;        // SHA-256 Hash dari data JSON / Payload
        string dataPayload;     // JSON String (Optional/Encrypted) - Untuk transparansi data non-sensitif
        uint256 timestamp;
        address actor;          // Address pengirim (System Wallet / Individual)
        string actorRole;       // ADMIN, PSIKOLOG, USER, SYSTEM
    }

    // Event untuk indexing off-chain
    event LogAdded(
        uint256 indexed id,
        string indexed reportCode,
        string actionType,
        address actor,
        uint256 timestamp
    );

    // Array penyimpanan log
    AuditLog[] public logs;

    // Mapping untuk pencarian cepat berdasarkan Kode Laporan
    mapping(string => uint256[]) public logsByReport;

    // Owner contract (Deployer)
    address public owner;

    constructor() {
        owner = msg.sender;
    }

    /**
     * @dev Mencatat aktivitas baru ke blockchain
     * @param _reportCode Kode laporan yang terkait
     * @param _actionType Jenis aktivitas (CREATE, UPDATE, dll)
     * @param _dataHash Hash dari data perubahan (integrity check)
     * @param _dataPayload Data JSON raw (jika data publik) atau Encrypted String (jika sensitif)
     * @param _actorRole Role pengguna yang melakukan aksi
     */
    function addLog(
        string memory _reportCode,
        string memory _actionType,
        string memory _dataHash,
        string memory _dataPayload,
        string memory _actorRole
    ) public {
        uint256 newId = logs.length;
        
        AuditLog memory newLog = AuditLog({
            id: newId,
            reportCode: _reportCode,
            actionType: _actionType,
            dataHash: _dataHash,
            dataPayload: _dataPayload,
            timestamp: block.timestamp,
            actor: msg.sender,
            actorRole: _actorRole
        });

        logs.push(newLog);
        logsByReport[_reportCode].push(newId);

        emit LogAdded(newId, _reportCode, _actionType, msg.sender, block.timestamp);
    }

    /**
     * @dev Mengambil jumlah total log
     */
    function getLogCount() public view returns (uint256) {
        return logs.length;
    }

    /**
     * @dev Mengambil detail log berdasarkan ID
     */
    function getLog(uint256 _id) public view returns (AuditLog memory) {
        require(_id < logs.length, "Log ID not found");
        return logs[_id];
    }

    /**
     * @dev Mengambil semua log untuk report tertentu
     * @param _reportCode Kode laporan
     */
    function getLogsByReport(string memory _reportCode) public view returns (AuditLog[] memory) {
        uint256[] memory ids = logsByReport[_reportCode];
        AuditLog[] memory reportLogs = new AuditLog[](ids.length);

        for (uint256 i = 0; i < ids.length; i++) {
            reportLogs[i] = logs[ids[i]];
        }

        return reportLogs;
    }
}
