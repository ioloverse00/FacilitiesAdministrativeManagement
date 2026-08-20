# Visitor ID Extraction Fixtures

Use this directory only for synthetic, mock, redacted, or explicitly approved test ID images.

Do not commit real visitor IDs, real government IDs, unredacted identity documents, or images containing private personal data.

Each fixture should be listed in `fixtures.json` with:

- `fixture`: stable fixture name
- `image`: relative image filename
- `expected.document_detected`
- `expected.full_name`
- `expected.id_type`
- `expected.id_last4`
- `expected.needs_review`

The evaluator uses these images to validate the AI-assisted extraction layer. It does not write raw model responses to logs.

## Current Fixture Targets

`philippine_drivers_license_sample_01.jpg` should validate that Philippine Driver's License layouts treat `Last Name, First Name, Middle Name` as a field label and extract the cardholder value directly below it, such as `DELA CRUZ, JUAN PEDRO GARCIA`.

Do not hardcode that sample name or image in extraction code. The rule must generalize to similar driver's license layouts.
