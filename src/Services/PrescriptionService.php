<?php

/**
 * PrescriptionService
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\Search\FhirSearchWhereClauseBuilder;
use OpenEMR\Validators\PatientValidator;
use OpenEMR\Validators\PrescriptionValidator;
use OpenEMR\Validators\ProcessingResult;

class PrescriptionService extends BaseService
{
    private const DRUGS_TABLE = "drugs";
    private const PRESCRIPTION_TABLE = "prescriptions";
    private const PATIENT_TABLE = "patient_data";
    private const ENCOUNTER_TABLE = "form_encounter";
    private const PRACTITIONER_TABLE = "users";

    /**
     * @var PatientValidator
     */
    private $patientValidator;

    /**
     * @var PrescriptionValidator
     */
    private $prescriptionValidator;

    /**
     * Default constructor.
     */
    public function __construct()
    {
        parent::__construct(self::PRESCRIPTION_TABLE);
        UuidRegistry::createMissingUuidsForTables([self::PRESCRIPTION_TABLE, self::PATIENT_TABLE, self::ENCOUNTER_TABLE,
            self::PRACTITIONER_TABLE, self::DRUGS_TABLE]);
        $this->patientValidator = new PatientValidator();
        $this->prescriptionValidator = new PrescriptionValidator();
    }

    public function getUuidFields(): array
    {
        return ['uuid', 'euuid', 'pruuid', 'drug_uuid', 'puuid'];
    }

    public function insert($data)
    {
        $processingResult = $this->prescriptionValidator->validate(
            $data,
            PrescriptionValidator::DATABASE_INSERT_CONTEXT
        );

        if (!$processingResult->isValid()) {
            return $processingResult;
        }

        $puuidBytes = UuidRegistry::uuidToBytes($data['puuid']);
        $data['patient_id'] = $this->getIdByUuid($puuidBytes, self::PATIENT_TABLE, "pid");
        $data['uuid'] = (new UuidRegistry(['table_name' => self::PRESCRIPTION_TABLE]))->createUuid();

        $query = $this->buildInsertColumns($data);
        $sql = " INSERT INTO prescriptions SET";
        $sql .= "     date_added=NOW(),";
        $sql .= "     created_by=NOW(),";
        $sql .= $query['set'];
        $results = sqlInsert(
            $sql,
            $query['bind']
        );

        if ($results) {
            $processingResult->addData(array(
                'id' => $results,
                'uuid' => UuidRegistry::uuidToString($data['uuid'])
            ));
        } else {
            $processingResult->addInternalError("error processing SQL Insert");
        }

        return $processingResult;
    }

    public function update($puuid, $presuuid, $data)
    {
        if (empty($data)) {
            $processingResult = new ProcessingResult();
            $processingResult->setValidationMessages("Invalid Data");
            return $processingResult;
        }
        $data["uuid"] = $presuuid;
        $data["puuid"] = $puuid;
        $processingResult = $this->prescriptionValidator->validate(
            $data,
            PrescriptionValidator::DATABASE_UPDATE_CONTEXT
        );

        if (!$processingResult->isValid()) {
            return $processingResult;
        }

        $validatePrescriptionOwner = $this->prescriptionValidator->validatePrescriptionBelongPatient($data['puuid'], $data['uuid']);
        if (!$validatePrescriptionOwner) {
            $processingResult->setValidationMessages([
                "presuuid" => "Prescription doesn't belong to Patient"
            ]);

            return $processingResult;
        }

        $query = $this->buildUpdateColumns($data);
        $uuidBinary = UuidRegistry::uuidToBytes($data['uuid']);
        $query['bind'][] = $uuidBinary;
        $sql = "UPDATE prescriptions SET {$query['set']} WHERE `uuid` = ?";
        $sqlResult = sqlStatement($sql, array_merge($query['bind']));

        if (!$sqlResult) {
            $processingResult->addInternalError("error processing SQL Update");
        } else {
            $processingResult = $this->getOne($presuuid);
        }

        return $processingResult;
    }

    /**
     * Returns a single prescription record by id.
     * @param $uuid - The prescription uuid identifier in string format.
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function getOne($uuid, $puuidBind = null)
    {
        return $this->getAll(['prescriptions.uuid' => $uuid], $puuidBind);
    }

    /**
     * Returns a list of prescription matching optional search criteria.
     * Search criteria is conveyed by array where key = field/column name, value = field value.
     * If no search criteria is provided, all records are returned.
     *
     * @param array $search search array parameters
     * @param bool $isAndCondition specifies if AND condition is used for multiple criteria. Defaults to true.
     * @param mixed $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function getAll($search = array(), $isAndCondition = true, $puuidBind = null)
    {
        $sqlBindArray = array();

        if (isset($search['patient.uuid'])) {
            $isValidPatient = $this->patientValidator->validateId(
                'uuid',
                self::PATIENT_TABLE,
                $search['patient.uuid'],
                true
            );
            if ($isValidPatient !== true && !$isValidPatient->isValid()) {
                return $isValidPatient;
            }
            $search['patient.puuid'] = UuidRegistry::uuidToBytes($search['patient.uuid']);
            unset($search['patient.uuid']);
        }

        if (isset($search['prescriptions.uuid'])) {
            $isValidPatient = $this->patientValidator->validateId(
                'uuid',
                self::PRESCRIPTION_TABLE,
                $search['prescriptions.uuid'],
                true
            );
            if ($isValidPatient != true) {
                return $isValidPatient;
            }
            $search['combined_prescriptions.uuid'] = UuidRegistry::uuidToBytes($search['prescriptions.uuid']);
            // same as before
            unset($search['prescriptions.uuid']);
        }

        if (!empty($puuidBind)) {
            // code to support patient binding
            $isValidPatient = $this->patientValidator->validateId(
                'uuid',
                self::PATIENT_TABLE,
                $puuidBind,
                true
            );
            if ($isValidPatient != true) {
                return $isValidPatient;
            }
        }

        // order comes from our MedicationRequest intent value set, since we are only reporting on completed prescriptions
        // we will put the intent down as 'order' @see http://hl7.org/fhir/R4/valueset-medicationrequest-intent.html
        $sql = "SELECT
                combined_prescriptions.uuid
                ,combined_prescriptions.source_table
                ,combined_prescriptions.drug
                ,combined_prescriptions.active
                ,combined_prescriptions.intent
                ,combined_prescriptions.category
                ,combined_prescriptions.intent_title
                ,combined_prescriptions.category_title
                ,'Community' AS category_text
                ,combined_prescriptions.rxnorm_drugcode
                ,combined_prescriptions.date_added
                ,combined_prescriptions.unit
                ,combined_prescriptions.`interval`
                ,combined_prescriptions.route
                ,combined_prescriptions.note
                ,combined_prescriptions.status
                ,combined_prescriptions.drug_dosage_instructions
                ,patient.puuid
                ,encounter.euuid
                ,practitioner.pruuid
                ,drug_uuid

                ,routes_list.route_id
                ,routes_list.route_title
                ,routes_list.route_codes

                ,units_list.unit_id
                ,units_list.unit_title
                ,units_list.unit_codes

                ,intervals_list.interval_id
                ,intervals_list.interval_title
                ,intervals_list.interval_codes

                FROM (
                      SELECT
                             prescriptions.uuid
                            ,'prescriptions' AS 'source_table'
                            ,prescriptions.drug
                            ,prescriptions.active
                            ,prescriptions.end_date
                            ,'order' AS intent
                            ,'Order' AS intent_title
                            ,'community' AS category
                            ,'Home/Community' as category_title
                            ,IF(prescriptions.rxnorm_drugcode!=''
                                ,prescriptions.rxnorm_drugcode
                                ,IF(drugs.drug_code IS NULL, '', concat('RXCUI:',drugs.drug_code))
                            ) AS 'rxnorm_drugcode'
                            ,date_added
                            ,COALESCE(prescriptions.unit,drugs.unit) AS unit
                            ,prescriptions.`interval`
                            ,COALESCE(prescriptions.`route`,drugs.`route`) AS 'route'
                            ,prescriptions.`note`
                            ,patient_id
                            ,encounter
                            ,provider_id
                            ,drugs.uuid AS drug_uuid
                            ,prescriptions.drug_dosage_instructions
                            ,CASE
                                WHEN prescriptions.end_date IS NOT NULL AND prescriptions.active = '1' THEN 'completed'
                                WHEN prescriptions.active = '1' THEN 'active'
                                ELSE 'stopped'
                            END as 'status'

                    FROM
                        prescriptions
                    LEFT JOIN
                        -- @brady.miller so drug_id in my databases appears to always be 0 so I'm not sure I can grab anything here.. I know WENO doesn't populate this value...
                        drugs ON prescriptions.drug_id = drugs.drug_id
                    UNION
                    SELECT
                        lists.uuid
                        ,'lists' AS 'source_table'
                        ,lists.title AS drug
                        ,activity AS active
                        ,lists.enddate AS end_date
                        ,lists_medication.request_intent AS intent
                        ,lists_medication.request_intent_title AS intent_title
                        ,lists_medication.usage_category AS category
                        ,lists_medication.usage_category_title AS category_title
                        ,lists.diagnosis AS rxnorm_drugcode
                        ,`date` AS date_added
                        ,NULL as unit
                        ,NULL as 'interval'
                        ,NULL as `route`
                        ,lists.comments as 'note'
                        ,pid AS patient_id
                        ,issues_encounter.issues_encounter_encounter as encounter
                        ,users.id AS provider_id
                        ,NULL as drug_uuid
                        ,lists_medication.drug_dosage_instructions
                        ,CASE
                                WHEN lists.enddate IS NOT NULL AND lists.activity = 1 THEN 'completed'
                                WHEN lists.activity = 1 THEN 'active'
                                ELSE 'stopped'
                        END as 'status'
                    FROM
                        lists
                    LEFT JOIN
                            users ON users.username = lists.user
                    LEFT JOIN
                        lists_medication ON lists_medication.list_id = lists.id
                    LEFT JOIN
                    (
                       select
                              pid AS issues_encounter_pid
                            , list_id AS issues_encounter_list_id
                            -- lists have a 0..* relationship with issue_encounters which is a problem as FHIR treats medications as a 0.1
                            -- we take the very first encounter that the issue was tied to.
                            , min(encounter) AS issues_encounter_encounter FROM issue_encounter GROUP BY pid,list_id
                    ) issues_encounter ON lists.pid = issues_encounter.issues_encounter_pid AND lists.id = issues_encounter.issues_encounter_list_id
                    WHERE
                        type = 'medication'
                ) combined_prescriptions
                LEFT JOIN
                (
                  SELECT
                    option_id AS route_id
                    ,title AS route_title
                    ,codes AS route_codes
                  FROM list_options
                  WHERE list_id='drug_route'
                ) routes_list ON routes_list.route_id = combined_prescriptions.route
                LEFT JOIN
                (
                  SELECT
                    option_id AS interval_id
                    ,title AS interval_title
                    ,codes AS interval_codes
                  FROM list_options
                  WHERE list_id='drug_route'
                ) intervals_list ON intervals_list.interval_id = combined_prescriptions.interval
                LEFT JOIN
                (
                  SELECT
                    option_id AS unit_id
                    ,title AS unit_title
                    ,codes AS unit_codes
                  FROM list_options
                  WHERE list_id='drug_route'
                ) units_list ON units_list.unit_id = combined_prescriptions.unit
                LEFT JOIN (
                    select uuid AS puuid
                    ,pid
                    FROM patient_data
                ) patient
                ON patient.pid = combined_prescriptions.patient_id
                LEFT JOIN (
                    SELECT
                        encounter,
                        uuid AS euuid
                    FROM form_encounter
                ) encounter
                ON encounter.encounter = combined_prescriptions.encounter
                LEFT JOIN (
                    SELECT
                           id AS practitioner_id
                           ,uuid AS pruuid
                    FROM users
                    WHERE users.npi IS NOT NULL AND users.npi != ''
                ) practitioner
                ON practitioner.practitioner_id = combined_prescriptions.provider_id";

        $whereClause = FhirSearchWhereClauseBuilder::build($search, $isAndCondition);

        $sql .= $whereClause->getFragment();
        $sqlBindArray = $whereClause->getBoundValues();
        $statementResults = QueryUtils::sqlStatementThrowException($sql, $sqlBindArray);

        $processingResult = new ProcessingResult();
        while ($row = sqlFetchArray($statementResults)) {
            $record = $this->createResultRecordFromDatabaseResult($row);
            $processingResult->addData($record);
        }
        return $processingResult;
    }

    protected function createResultRecordFromDatabaseResult($row)
    {
        $record = parent::createResultRecordFromDatabaseResult($row);

        if ($record['rxnorm_drugcode'] != "") {
            $codes = $this->addCoding($row['rxnorm_drugcode']);
            $updatedCodes = [];
            foreach ($codes as $code => $codeValues) {
                if (empty($codeValues['description'])) {
                    // use the drug name if for some reason we have no rxnorm description from the lookup
                    $codeValues['description'] = $row['drug'];
                }
                $updatedCodes[$code] = $codeValues;
            }
            $record['drugcode'] = $updatedCodes;
        }

        return $record;
    }

    public function delete($puuid, $uuid)
    {
        $processingResult = new ProcessingResult();

        $isValidPrescription = $this->prescriptionValidator->validateId("uuid", self::PRESCRIPTION_TABLE, $uuid, true);
        $isPatientValid = $this->prescriptionValidator->validateId("uuid", self::PATIENT_TABLE, $puuid, true);

        if ($isValidPrescription !== true) {
            return $processingResult;
        }

        if ($isPatientValid !== true) {
            return $processingResult;
        }

        $puuidBytes = UuidRegistry::uuidToBytes($puuid);
        $auuid = UuidRegistry::uuidToBytes($uuid);
        $pid = $this->getIdByUuid($puuidBytes, self::PATIENT_TABLE, "pid");
        $sql = "DELETE FROM prescriptions WHERE patient_id=? AND uuid=?";

        $results = sqlStatement($sql, array($pid, $auuid));

        if ($results) {
            $processingResult->addData(array(
                'uuid' => $uuid
            ));
        } else {
            $processingResult->addInternalError("error processing SQL Insert");
        }

        return $processingResult;
    }
}
