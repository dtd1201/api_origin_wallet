# Nium Corporate Constants Mapping

Source: Nium "Fetch Constant Enums" documentation. Stored values are always the response `code`; response `description` is display-only.

| UI field | Nium API field | Constant category | Stored value |
|---|---|---|---|
| Registered/business/person country | `addresses.*.country`, `applicant.address.country`, `stakeholders.individual[*].address.country` | `countryName` | `code` |
| Operating countries | `natureOfBusiness.operatingCountries[*]` | `countryOfOperation` | `code` |
| Transaction countries | `expectedAccountUsage.{credit,debit}.topTransactionCountries[*]` | `countryOfOperation` | `code` |
| State/subdivision | `addresses.*.state`, `applicant.address.state`, `stakeholders.individual[*].address.state` | `isoState` with `countryCode` | `code` |
| Business type | `businessType` | `businessType` | `code` |
| Industry | `natureOfBusiness.industryCodes[*]` | `industrySector` | `code` |
| Monthly volume | `expectedAccountUsage.{credit,debit}.monthlyTransactionVolume` | `monthlyTransactionVolume` | `code` |
| Monthly transaction count | `expectedAccountUsage.{credit,debit}.monthlyTransactions` | `monthlyTransactions` | `code` |
| Average transaction value | `expectedAccountUsage.{credit,debit}.averageTransactionValue` | `averageTransactionValue` | `code` |
| Intended account uses | `expectedAccountUsage.intendedUses[*]` | `intendedUseOfAccount` | `code` |
| Annual turnover | `sizeOfBusiness.annualTurnover` | `annualTurnover` | `code` |
| Employee count | `sizeOfBusiness.totalEmployees` | `totalEmployees` | `code` |
| Identity/corporate document type | `documents[*].type`, `applicant.documents[*].type`, `stakeholders.individual[*].documents[*].type` | `documentType` | `code` |
| Applicant/stakeholder position | `applicant.positions[*].title`, `stakeholders.individual[*].positions[*].title` | `position` | `code` |
