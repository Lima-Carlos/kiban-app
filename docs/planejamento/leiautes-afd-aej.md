# AFD and AEJ official file layouts (Portaria MTP nº 671/2021): implementation reference

Research date: 09/11/2026. Every table below was transcribed from the **official PDFs downloaded from gov.br**
on that date. Where something is *not* stated in an official source, it is marked **[NOT OFFICIAL]** or **[AMBIGUOUS]**.

## 0. Sources and the version situation (read first)

| What | URL | Notes |
|---|---|---|
| Ministry REP page (list of current specs) | https://www.gov.br/trabalho-e-emprego/pt-br/assuntos/inspecao-do-trabalho/fiscalizacao-do-trabalho/rep | Lists "Leiaute do AFD … - **31/07/2026**" and "Leiaute do AEJ … - **31/07/2026**" |
| **AFD layout (current, version 004)** | https://www.gov.br/trabalho-e-emprego/pt-br/assuntos/inspecao-do-trabalho/fiscalizacao-do-trabalho/leiaute-do-arquivo-fonte-de-dados-afd.pdf | 6 pages. Same URL used to serve version "003" before; search-engine caches still show "003" |
| **AEJ layout (current, version 002)** | https://www.gov.br/trabalho-e-emprego/pt-br/assuntos/inspecao-do-trabalho/fiscalizacao-do-trabalho/leiaute-do-arquivo-eletronico-de-jornada-aej.pdf | 6 pages |
| Portaria 671/2021, compiled text (21/07/2026) | https://www.gov.br/trabalho-e-emprego/pt-br/assuntos/legislacao/portarias-1/portarias-vigentes-3/WORDPortarian671de8denovembrode2021compilada21.07.2026.pdf | Arts. 73–99. Revoked Anexo V (AFD 003) and Anexo VI (AEJ 001) still printed. Anexo IX = REP-P requirements |
| Official FAQ "Perguntas e Respostas REP" (59 questions, latest from 28/03/2025) | https://www.gov.br/trabalho-e-emprego/pt-br/assuntos/inspecao-do-trabalho/fiscalizacao-do-trabalho/Perguntas%20e%20Respostas%20REP | Official interpretations (CRC, signature, naming, CPF padding, AEJ examples) |
| Portaria MTP 1.486/2022 (moved layouts out of the annexes to gov.br, added CAdES/PAdES) | https://www.in.gov.br/en/web/dou/-/portaria-mtp-n-1.486-de-3-de-junho-de-2022-405577190 | |
| Portaria MTP 3.717/2022 (AEJ deadline 11/01/2023, art. 97) | https://www.in.gov.br/en/web/dou/-/portaria-mtp-n-3.717-de-9-de-novembro-de-2022-443015406 | Not 2023. "MTE 3.717/2023" does not exist; the 2023 amendment is MTE 3.784/2023, which did not touch REP |
| Portaria MTP 4.198/2022 (art. 86 §§1–2: who signs) | https://www.gov.br/trabalho-e-emprego/pt-br/assuntos/inspecao-do-trabalho/fiscalizacao-do-trabalho/portaria-mtp-no-4-198_19_12_2022_alterou-o-artigo-86_assinatura_arquivos_rep.pdf | |

**Important: the brief asked for AFD "version 003", but the official AFD layout is now version `004` (published 31/07/2026).**
The official AEJ layout is now `002` (it was `001`). I diffed the new PDFs against the revoked annexes in the compiled Portaria (v003 / v001).
The only changes are these:

| File | Field | v003 / v001 (old) | v004 / v002 (current) |
|---|---|---|---|
| AFD type 1 | f4 CNPJ/CPF do empregador | N | **A** |
| AFD type 1 | f11 versão | "003" | **"004"** |
| AFD type 1 | f13 CNPJ/CPF do fabricante/desenvolvedor | N | **A** |
| AFD type 2 | f6 CNPJ/CPF do empregador | N | **A** |
| AFD all CRC fields | description | "CRC-16 do registro" | "…(representação hexadecimal sem o "0x")" + KERMIT note moved into the layout (it was FAQ 35 before) |
| AEJ type 01 | idtEmpregador | N | **A** |
| AEJ type 01 | versaoAej | "001" | **"002"** |
| AEJ type 08 | idtDesenv | N | **A** |

All positions and sizes are unchanged. The change is clearly the **alphanumeric CNPJ** (Receita Federal, from July 2026). The PDF itself has no changelog, so this cause is my inference.
**Recommendation:** generate `004`/`002` and keep the parser tolerant of `003`/`001`, since the positions are identical.

---

## 1. AFD (Arquivo Fonte de Dados): current version 004

Source: AFD layout PDF (gov.br, 31/07/2026); Portaria 671 arts. 81, 86, 88 and Anexo IX; FAQ 28, 33, 35, 39, 41, 42, 49, 50.

### 1.1 General rules (official, items 1–10 of the layout)

| Rule | Official content |
|---|---|
| Encoding | Text, "ASCII da norma **ISO 8859-1**" (Latin-1). Every line has a fixed length, so count bytes in Latin-1, not UTF-8 |
| Line terminator | Each line = one record, ending with chars 13 and 10 (**CRLF**). The official text does not say whether the last line (signature) also ends in CRLF; ending it in CRLF follows "cada linha … terminando com" |
| Order | Records ordered by **NSR** |
| Blank lines | Not allowed |
| Types | `N` numeric; `A` alphanumeric; `D` = `AAAA-MM-dd`; `DH` = `AAAA-MM-ddThh:mm:00ZZZZZ` (seconds always `00`; `ZZZZZ` = sign + hhmm, e.g. `2021-04-27T16:44:00-0300`; no colon in the offset) |
| Padding | "O preenchimento dos campos deve se iniciar pela esquerda e posições não utilizadas devem ser preenchidas com espaço": **left-aligned, right-padded with spaces**, for all fields, numeric ones included |
| CPF exception (FAQ 50) | For a 12-position CPF field, both `0XXXXXXXXXXX` (zero-left) and `XXXXXXXXXXX ` (space-right) are valid |
| INPI number (FAQ 49) | Numeric field: only the digits/characters left after removing spaces, "BR" and punctuation, e.g. "BR 51 2022 XXXXXX-Y" → `512022XXXXXXY`, then space-padded on the right |
| CRC | Types **1 to 5** carry a CRC-16. For REP-A / REP-P: **CRC-16/KERMIT** ("CCITT-TRUE"); `"123456789"` → `0x2189`, written as 4 uppercase-looking hex chars `2189` (no `0x`) |
| SHA-256 | Type **7** only, field 8 (64 chars) |
| No CRC/hash | Types 6 and 9 and the signature line |
| File name | `"AFD"` + INPI registration number + CNPJ/CPF of the employer + `"REP_P"` (REP-C: `AFD`+nº fabricação+CNPJ/CPF+`REP_C`; REP-A: `AFD`+CNPJ/CPF+`REP_A`) |

**[AMBIGUOUS] Extension and separators in the name.** The layout gives no extension. FAQ 28's example is `"00000000000000REP_A.txt"`, which (oddly) has no "AFD" prefix but uses `.txt`.
FAQ 42 allows a suffix (sequential number or generation date/time) when there are several files.
Recommended name: `AFD{INPI digits}{CNPJ/CPF digits}REP_P.txt` (plus an optional suffix before `.txt`).
The signature file is `<same name>.txt.p7s`.

**CRC-16/KERMIT parameters** (standard catalogue; the official text only names the algorithm and the check value):
poly 0x1021, reflected in/out (implemented as 0x8408 right-shift), init 0x0000, xorout 0x0000, check("123456789") = 0x2189.
I verified the 0x2189 check with the Python below.
**[NOT OFFICIAL]** The input to the CRC is "o registro" and is not further defined.
The only sensible reading is all characters of the line **before** the CRC field (positions 001 to n−4), in ISO-8859-1 bytes, without CRLF.

```python
def crc16_kermit(s: str) -> str:
    c = 0
    for b in s.encode('latin-1'):
        c ^= b
        for _ in range(8):
            c = (c >> 1) ^ 0x8408 if c & 1 else c >> 1
    return '%04X' % c        # '2189' for '123456789'
```

### 1.2 Which record types a REP-P emits

| Type | REP-P? | Basis |
|---|---|---|
| 1 Cabeçalho | Yes (first line) | layout |
| 2 Empresa (inclusion/change) | Yes | Anexo IX 6.1 |
| 3 Marcação REP-C/REP-A | **No** (REP-C/REP-A only) | layout title |
| 4 Ajuste do relógio | Yes, when the clock is adjusted | Anexo IX 6.2 |
| 5 Empregado I/A/E | Yes | Anexo IX 6.3 |
| 6 Eventos sensíveis | Yes, codes `02`, `07`, `08` only | layout |
| 7 Marcação REP-P | Yes | layout / Anexo IX 6.5 |
| 9 Trailer | Yes | layout |
| Signature line | Yes (literal text) | layout |

NSR (Anexo IX OBS + FAQ 41): **one NSR sequence per establishment (14-digit CNPJ or CPF)**, increments of 1, starting at 1 at the REP's first operation for that establishment.
NSR is shared by types 2–7, which are all "operations".
The AFD of a REP-P **may be split** (FAQ 39) and must be generatable for a time interval (Anexo IX 12). So NSRs in a file need not start at 1.

### 1.3 Type 1: Cabeçalho (302 chars)

| # | Pos | Size | Type | Content |
|---|---|---|---|---|
| 1 | 001-009 | 9 | N | `000000000` |
| 2 | 010-010 | 1 | N | `1` |
| 3 | 011-011 | 1 | N | Employer id type: `1` CNPJ, `2` CPF |
| 4 | 012-025 | 14 | **A** (v003: N) | Employer CNPJ or CPF |
| 5 | 026-039 | 14 | N | CNO or CAEPF, when it exists (optional, FAQ 47; blank → spaces per the padding rule) |
| 6 | 040-189 | 150 | A | Razão social / nome do empregador |
| 7 | 190-206 | 17 | N | REP-C: nº fabricação; REP-A: nº processo do último acordo/convenção (or `99999999999999999`); **REP-P: nº registro no INPI** |
| 8 | 207-216 | 10 | D | Data inicial dos registros no arquivo |
| 9 | 217-226 | 10 | D | Data final dos registros no arquivo |
| 10 | 227-250 | 24 | DH | Data e hora da geração do arquivo |
| 11 | 251-253 | 3 | N | Versão do leiaute: `004` |
| 12 | 254-254 | 1 | N | Fabricante/desenvolvedor id type: `1` CNPJ, `2` CPF |
| 13 | 255-268 | 14 | **A** (v003: N) | CNPJ/CPF do fabricante ou desenvolvedor do REP |
| 14 | 269-298 | 30 | A | Modelo (REP-C only; REP-P → 30 spaces) |
| 15 | 299-302 | 4 | A | CRC-16 (hex, no "0x") |

### 1.4 Type 2: Inclusão/alteração da identificação da empresa no REP (331 chars)

| # | Pos | Size | Type | Content |
|---|---|---|---|---|
| 1 | 001-009 | 9 | N | NSR |
| 2 | 010-010 | 1 | N | `2` |
| 3 | 011-034 | 24 | DH | Data e hora da gravação do registro |
| 4 | 035-048 | 14 | N | CPF do responsável pela inclusão ou alteração (14 positions for an 11-digit CPF; padding per rule 7 / FAQ 50 logic) |
| 5 | 049-049 | 1 | N | Employer id type: `1` CNPJ, `2` CPF |
| 6 | 050-063 | 14 | **A** (v003: N) | CNPJ ou CPF do empregador |
| 7 | 064-077 | 14 | N | CNO ou CAEPF, when it exists |
| 8 | 078-227 | 150 | A | Razão social ou nome |
| 9 | 228-327 | 100 | A | Local de prestação de serviços |
| 10 | 328-331 | 4 | A | CRC-16 |

### 1.5 Type 3: Marcação REP-C / REP-A (50 chars; NOT emitted by REP-P; needed only by a parser)

| # | Pos | Size | Type | Content |
|---|---|---|---|---|
| 1 | 001-009 | 9 | N | NSR |
| 2 | 010-010 | 1 | N | `3` |
| 3 | 011-034 | 24 | DH | Data e hora da marcação |
| 4 | 035-046 | 12 | N | CPF do empregado |
| 5 | 047-050 | 4 | A | CRC-16 |

### 1.6 Type 4: Ajuste do relógio (73 chars)

| # | Pos | Size | Type | Content |
|---|---|---|---|---|
| 1 | 001-009 | 9 | N | NSR |
| 2 | 010-010 | 1 | N | `4` |
| 3 | 011-034 | 24 | DH | Data e hora antes do ajuste |
| 4 | 035-058 | 24 | DH | Data e hora ajustada |
| 5 | 059-069 | 11 | N | CPF do responsável pela alteração |
| 6 | 070-073 | 4 | A | CRC-16 |

### 1.7 Type 5: Inclusão, alteração ou exclusão de empregado (118 chars)

| # | Pos | Size | Type | Content |
|---|---|---|---|---|
| 1 | 001-009 | 9 | N | NSR |
| 2 | 010-010 | 1 | N | `5` |
| 3 | 011-034 | 24 | DH | Data e hora da gravação do registro |
| 4 | 035-035 | 1 | A | Operation: `I` inclusão, `A` alteração, `E` exclusão |
| 5 | 036-047 | 12 | N | CPF do empregado |
| 6 | 048-099 | 52 | A | Nome do empregado |
| 7 | 100-103 | 4 | A | Demais dados de identificação do empregado (only 4 chars, contents free; spaces if none) |
| 8 | 104-114 | 11 | N | CPF do responsável pela alteração |
| 9 | 115-118 | 4 | A | CRC-16 |

### 1.8 Type 6: Eventos sensíveis do REP (36 chars, no CRC)

| # | Pos | Size | Type | Content |
|---|---|---|---|---|
| 1 | 001-009 | 9 | N | NSR |
| 2 | 010-010 | 1 | N | `6` |
| 3 | 011-034 | 24 | DH | Data e hora da gravação do registro |
| 4 | 035-036 | 2 | N | Event code (table below) |

| Code | Event | Who |
|---|---|---|
| 01 | Abertura do REP por manutenção ou violação | REP-C only |
| 02 | Retorno de energia | REP-C or **REP-P** |
| 03 | Introdução de dispositivo externo na Porta Fiscal | REP-C only |
| 04 | Retirada de dispositivo externo da Porta Fiscal | REP-C only |
| 05 | Emissão da Relação Instantânea de Marcações | REP-C only |
| 06 | Erro de impressão | REP-C only |
| 07 | Disponibilidade de serviço | **REP-P only** |
| 08 | Indisponibilidade de serviço | **REP-P only** |

### 1.9 Type 7: Marcação de ponto REP-P (137 chars)

| # | Pos | Size | Type | Content |
|---|---|---|---|---|
| 1 | 001-009 | 9 | N | NSR |
| 2 | 010-010 | 1 | N | `7` |
| 3 | 011-034 | 24 | DH | Data e hora da marcação (with the punch's timezone) |
| 4 | 035-046 | 12 | N | CPF do empregado (FAQ 50: `0`+11 digits or 11 digits+space) |
| 5 | 047-070 | 24 | DH | Data e hora de gravação do registro (with timezone) |
| 6 | 071-072 | 2 | N | Coletor: `01` app mobile; `02` browser; `03` app desktop; `04` dispositivo eletrônico; `05` outro dispositivo eletrônico |
| 7 | 073-073 | 1 | N | `0` on-line, `1` off-line (off-line support is optional, FAQ 52) |
| 8 | 074-137 | 64 | A | Código hash SHA-256 |

**Hash: official text, complete.** "Será utilizado o padrão SHA-256 … e seu cálculo será feito com base nos dados abaixo":

1. NSR (f1)
2. tipo do registro (f2)
3. data e hora da marcação (f3)
4. CPF do empregado (f4)
5. data e hora da gravação (f5)
6. identificador do coletor (f6)
7. on-line/off-line (f7)
8. "código hash (SHA-256) do **registro anterior, caso exista**"

**What the official sources do NOT say:**

- whether fields are concatenated as their fixed-width text (positions 001-073) or as trimmed values;
- whether there is any separator;
- whether the hex digest is upper- or lower-case;
- the byte encoding;
- whether "registro anterior" means the previous **type 7** record or the previous record of any type (type 7 is the only one with a hash, so the previous *hash* can only come from a type 7);
- whether the chain runs per establishment (per NSR sequence) or per file;
- what goes in for the first record.

I found no official worked example (not in the layout, the Portaria, or the 59-question FAQ).

**[NOT OFFICIAL] Recommended interpretation** (the one used by the third-party open-source implementations I found, e.g. the GitHub PRs below; no vendor confirmed it):

- `hash_n = SHA256( latin1( line_n[001..073] + hash_{n-1} ) )`. That is the record exactly as written in positions 1–73 (fixed-width, padded), with no separators, followed by the previous type-7 record's 64-char hex hash.
- Write the hex digest in lowercase or uppercase consistently. I found no rule.
- First type-7 record of the ARP (chain start): **nothing is appended** (empty string), from "caso exista".
- The chain belongs to the ARP and the NSR sequence (per establishment), not to the exported file. When a split AFD is exported, its first type-7 record still carries the hash computed from the real previous record, which may be outside the file.
  A parser can therefore only verify the chain from the 2nd type-7 record in the file on, unless it knows the previous hash.

Third-party implementations seen (not authoritative): https://github.com/wagnerra23/oimpresso.com/pull/8024 , https://github.com/infoxtec/infoxtec-escalas/pull/111 , https://github.com/RodrigorjSousa/injoy-fix-it/pull/131

The same hash must also appear on the **Comprovante de Registro de Ponto** (art. 79, VIII).

### 1.10 Type 9: Trailer (64 chars, no CRC)

| # | Pos | Size | Type | Content |
|---|---|---|---|---|
| 1 | 001-009 | 9 | N | `999999999` |
| 2 | 010-018 | 9 | N | Count of type 2 records in the file |
| 3 | 019-027 | 9 | N | Count of type 3 |
| 4 | 028-036 | 9 | N | Count of type 4 |
| 5 | 037-045 | 9 | N | Count of type 5 |
| 6 | 046-054 | 9 | N | Count of type 6 |
| 7 | 055-063 | 9 | N | Count of type 7 |
| 8 | 064-064 | 1 | N | `9` |

Type 1, the trailer itself, and the signature line are **not** counted.
**[AMBIGUOUS]** Count padding: rule 7 literally says left-aligned + spaces. Every known implementation zero-pads counts (`000000012`), as in `000000000`/`999999999`. Zero-padding is recommended, and the parser should accept both.

### 1.11 Signature line (100 chars, last line)

| # | Pos | Size | Type | Content |
|---|---|---|---|---|
| 1 | 001-100 | 100 | A | REP-A / REP-P: literal `ASSINATURA_DIGITAL_EM_ARQUIVO_P7S` (33 chars) + 67 spaces |

### 1.12 Digital signature (.p7s)

| Item | Official answer | Source |
|---|---|---|
| Standard | **CAdES**, stored in a **detached .p7s** file | Portaria art. 88 §2; FAQ 28 |
| Name | AFD file name + `.p7s` (e.g. `X.txt` → `X.txt.p7s`) | FAQ 28 |
| Certificate | Must be valid and issued under **ICP-Brasil** (qualified electronic signature, Lei 14.063/2020) | art. 88 caput |
| Who signs the AFD | **The manufacturer/developer of the REP**, with its own certificate | art. 86 §1 (Portaria 4.198/2022); FAQ 33 |
| Who signs the AEJ | **The PTRP developer or the employer** (art. 86 §2); FAQ 34 says "deve ser assinado pelo desenvolvedor do PTRP" (written before the §2 amendment) | |
| Comprovante (PDF) | **PAdES**, signed by the developer (FAQ 44: "com o certificado digital da empresa desenvolvedora", even when the developer also uses its own REP-P) | art. 88 §1; FAQ 30, 44 |

So for Kiban (REP-P developer), the AFD and the comprovantes are signed with **Kiban's own ICP-Brasil e-CNPJ**, not the customer's. Nothing restricts the certificate to A1 or A3; an A1 is the practical choice on a server.

**[NOT OFFICIAL] What exactly is signed.** No source says. The natural reading is that the detached CAdES signs the **entire .txt file as delivered**, bytes included, with the literal signature line (it is a constant placeholder, so there is no recursion problem).
The CAdES profile (CAdES-BES vs AD-RB of ICP-Brasil DOC-ICP-15) and the hash algorithm are not specified. SHA-256 with a CAdES-BES / ICP-Brasil AD-RB policy is the safe default.

---

## 2. AEJ (Arquivo Eletrônico de Jornada): current version 002

Source: AEJ layout PDF (gov.br, 31/07/2026); Portaria art. 82–86, 88; FAQ 19, 21, 29, 34, 51, 53–59.

### 2.1 General rules (official)

| Rule | Content |
|---|---|
| Encoding | Text, ISO 8859-1 |
| Line end | CRLF (chars 13 10) |
| Fields | Variable length. After each field **except the last** of the record, a `\|` delimiter. FAQ 59: the pipe is mandatory |
| Blank lines | Not allowed |
| Types | `N`; `A`; `H` = `hhmm`; `D` = `AAAA-MM-dd`; `DH` = `AAAA-MM-ddThh:mm:00ZZZZZ` |
| Sizes | "1 a 150" = variable, 1–150 chars; "0 a 9" = optional (may be empty); "0 ou 4" = empty or exactly 4 |
| Timezone | Always required in DH, even when the source AFD lacks it (FAQ 53) |
| Record order | Not specified. Natural order: 01, 02…, 03…, 04…, 05…, 06…, 07…, 08, 99, signature |
| Signature line | Last line: `ASSINATURA_DIGITAL_EM_ARQUIVO_P7S` + spaces to 100 chars |

**[AMBIGUOUS] Trailing pipe.** The rule says no pipe after the last field. But the official FAQ 51 example ends records with a trailing `|` when the last field (motivo) is empty: `05|2|2022-01-01T11:30:00-0300|1|S|1|O||`.
The consistent reading is that the last field is simply empty. The record still has the right number of fields: 9 fields → 8 pipes, so `…|O||` = fields 7 `O`, 8 empty, 9 empty.
**Rule: always emit all fields of the record, empty ones included, joined by `|`.**

**File name: not defined by the official layout.** The only hint is FAQ 29's example `0001AEJ.txt` → signature `0001AEJ.txt.p7s`. Any name ending in `.txt` works. Suggested: `AEJ_{CNPJ}_{AAAAMMdd}_{AAAAMMdd}.txt`.

**Signature:** CAdES detached `.p7s`, ICP-Brasil, signed by the **PTRP developer or the employer** (art. 86 §2, art. 88 §2; FAQ 29, 34).

### 2.2 Type 01: Cabeçalho (exactly one)

| # | Name | Size | Type | Content |
|---|---|---|---|---|
| 1 | tipoReg | 2 | N | `01` |
| 2 | tpIdtEmpregador | 1 | N | `1` CNPJ, `2` CPF |
| 3 | idtEmpregador | 11 or 14 | **A** (v001: N) | CNPJ or CPF |
| 4 | caepf | 14 | N | CAEPF, if any (optional → empty) |
| 5 | cno | 12 | N | CNO, if any (optional → empty) |
| 6 | razaoOuNome | 1–150 | A | Razão social / nome |
| 7 | dataInicialAej | 10 | D | Start date |
| 8 | dataFinalAej | 10 | D | End date |
| 9 | dataHoraGerAej | 24 | DH | Generation date/time |
| 10 | versaoAej | 3 | A | `002` |

### 2.3 Type 02: REPs utilizados

| # | Name | Size | Type | Content |
|---|---|---|---|---|
| 1 | tipoReg | 2 | N | `02` |
| 2 | idRepAej | 1–9 | N | REP identifier **inside this AEJ** (any number you choose, e.g. 1, 2…) |
| 3 | tpRep | 1 | N | `1` REP-C, `2` REP-A, `3` REP-P |
| 4 | nrRep | 17 | N | REP-C: nº fabricação; REP-A: nº processo do acordo (or `99999999999999999`); **REP-P: nº registro no INPI** (digits only, FAQ 49). Mandatory when some 05 has fonteMarc `O` |

**[AMBIGUOUS]** Size "17" for nrRep. The pipe format suggests the real value without padding (FAQ 49 says "No caso do AFD, as demais posições … preenchidas com espaço", which implies no padding in the AEJ).

### 2.4 Type 03: Vínculos

| # | Name | Size | Type | Content |
|---|---|---|---|---|
| 1 | tipoReg | 2 | N | `03` |
| 2 | idtVinculoAej | 1–9 | N | Employee-link identifier inside this AEJ |
| 3 | cpf | 11 | N | CPF (11 digits, no punctuation) |
| 4 | nomeEmp | 1–150 | A | Nome do empregado |

### 2.5 Type 04: Horário contratual

| # | Name | Size | Type | Content |
|---|---|---|---|---|
| 1 | tipoReg | 2 | N | `04` |
| 2 | codHorContratual | 1–30 | A | Schedule code inside this AEJ |
| 3 | durJornada | 1–12 | N | **Daily** duration in minutes (FAQ 21). For night work, count with the reduced night hour (obs. 3) |
| 4 | hrEntrada01 | 4 | H | 1st entry `hhmm` |
| 5 | hrSaida01 | 4 | H | 1st exit |
| 6 | hrEntrada02 | 0 or 4 | H | 2nd entry |
| 7 | hrSaida02 | 0 or 4 | H | 2nd exit |
| … | hrEntradaNN / hrSaidaNN | 4 | H | More pairs are appended in sequence (obs. 2) |

FAQ 57: the contractual schedule is mandatory for every employee's daily journeys.
FAQ 54: a pre-assigned intrajornada break must appear as actual 05 records with fonteMarc `P`, not just in the schedule.

### 2.6 Type 05: Marcações

| # | Name | Size | Type | Content |
|---|---|---|---|---|
| 1 | tipoReg | 2 | N | `05` |
| 2 | idtVinculoAej | 1–9 | N | Link from 03 |
| 3 | dataHoraMarc | 24 | DH | Punch date/time with timezone |
| 4 | idRepAej | 0–9 | N | REP from 02 (empty when there is no REP, e.g. a manual inclusion) |
| 5 | tpMarc | 1 | A | `E` entrada, `S` saída, `D` desconsiderada |
| 6 | seqEntSaida | 3 | N | Number of the E/S pair within the daily journey (1, 2, 3…). FAQ 51 writes `1`, `2` unpadded despite size 3 |
| 7 | fonteMarc | 1 | A | `O` original do REP; `I` incluída manualmente; `P` pré-assinalada; `X` incluída (horário predefinido) para ponto por exceção; `T` outras fontes |
| 8 | codHorContratual | 0–30 | A | Mandatory on the first entry of the journey (`tpMarc=E` and `seqEntSaida=1`), empty otherwise |
| 9 | motivo | 0–150 | A | Mandatory when `tpMarc=D` or `fonteMarc=I` |

**How corrections are represented:**

- **Inclusion** of a forgotten punch: a 05 with `fonteMarc=I`, a `motivo`, and normally an empty `idRepAej` (it did not come from a REP). Give it the `tpMarc` (`E`/`S`) and `seqEntSaida` it fills.
- **Disregard** (desconsideração): the original punch stays (it is in the AFD) as a 05 with `tpMarc=D`, `fonteMarc=O`, the `idRepAej` of its REP, and a `motivo`. A `D` record is not part of an E/S pair.
  **[AMBIGUOUS]** What goes in seqEntSaida for `D` is not specified. It is a mandatory 3-size N field. Repeat the sequence number of the pair it would have belonged to, or use `0`.
- Every journey must end up with complete E/S pairs. An odd count is "automatically wrong" (FAQ 56).
- A journey that crosses midnight keeps one sequence (E1, S1, E2, S2…) across both dates (FAQ 58).
- The PTRP must not insert punches to create overtime (FAQ 55).

Official example (FAQ 51; employee idtVinculoAej=2, REP idRepAej=1, schedule CH001):
```
05|2|2022-01-01T07:00:00-0300|1|E|1|O|CH001|
05|2|2022-01-01T11:30:00-0300|1|S|1|O||
05|2|2022-01-01T13:32:00-0300|1|E|2|O||
05|2|2022-01-01T17:25:00-0300|1|S|2|O||
```

### 2.7 Type 06: Matrícula eSocial (only for employees with more than one vínculo in the AEJ)

| # | Name | Size | Type | Content |
|---|---|---|---|---|
| 1 | tipoReg | 2 | N | `06` |
| 2 | idtVinculoAej | 1–9 | N | Link from 03 |
| 3 | matEsocial | 1–30 | A | Matrícula do vínculo no eSocial |

### 2.8 Type 07: Ausências e banco de horas

| # | Name | Size | Type | Content |
|---|---|---|---|---|
| 1 | tipoReg | 2 | N | `07` |
| 2 | idtVinculoAej | 1–9 | N | Link from 03 |
| 3 | tipoAusenOuComp | 1 | N | `1` DSR; `2` falta não justificada; `3` movimento no banco de horas; `4` folga compensatória de feriado |
| 4 | data | 10 | D | Date |
| 5 | qtMinutos | 0–12 | N | Minutes. Mandatory if tipo = `3` |
| 6 | tipoMovBH | 0 or 1 | N | `1` inclusão (credit) no banco; `2` compensação (debit). Mandatory if tipo = `3` |

These are the only absence codes. There is no code for justified absences, sick leave or vacations.
**[NOT OFFICIAL]** The usual practice is to report nothing for those days (the espelho explains them), or to use `T`/`I` punches when hours are credited.

### 2.9 Type 08: Identificação do PTRP

| # | Name | Size | Type | Content |
|---|---|---|---|---|
| 1 | tipoReg | 2 | N | `08` |
| 2 | nomeProg | 1–150 | A | PTRP name |
| 3 | versaoProg | 1–8 | A | PTRP version |
| 4 | tpIdtDesenv | 1 | N | `1` CNPJ, `2` CPF |
| 5 | idtDesenv | 11 or 14 | **A** (v001: N) | Developer CNPJ/CPF |
| 6 | razaoNomeDesenv | 1–150 | A | Developer name |
| 7 | emailDesenv | 1–50 | N (sic: obviously alphanumeric) | Developer e-mail |

### 2.10 Type 99: Trailer

| # | Name | Size | Type | Content |
|---|---|---|---|---|
| 1 | tipoReg | 2 | N | `99` |
| 2–9 | qtRegistrosTipo01 … qtRegistrosTipo08 | 1–9 each | N | Counts of types 01, 02, 03, 04, 05, 06, 07, 08 (in that order, unpadded) |

The trailer does not count itself or the signature line. Example: `99|1|1|25|3|840|0|12|1`.

### 2.11 Signature line

| # | Name | Size | Type | Content |
|---|---|---|---|---|
| 1 | assinDigital | 100 | A | `ASSINATURA_DIGITAL_EM_ARQUIVO_P7S` + 67 spaces (fixed 100, no pipe) |

### 2.12 How REP-P / AFD data links into the AEJ

- One 02 record per REP used. For Kiban's REP-P: `02|1|3|{INPI digits}`, the same INPI number as AFD header field 7.
- Each AFD **type 7** becomes one AEJ **05** with `idRepAej=1`, `fonteMarc=O`, the same `dataHoraMarc` (AFD type 7 field 3, with timezone), and the employee's `idtVinculoAej`.
  The link is CPF (AFD f4) → 03.cpf → idtVinculoAej.
- **No NSR or hash appears in the AEJ.** The link to the AFD is only CPF + date/time + REP number.
- Manual punches (`I`) and pre-assigned ones (`P`) have no AFD counterpart, so `idRepAej` stays empty. `nrRep` is mandatory only when some 05 uses `O`.

---

## 3. Official validator

**No official validator for AFD or AEJ files exists.** I found none on the Ministry's REP page, in the 59-question official FAQ, in the compiled Portaria, or in searches of gov.br.
The FAQ (Q32) only says signatures "podem ser verificadas sempre que surgirem dúvidas", e.g. during an inspection or in court.

For the **signature** only, the government's ICP-Brasil verifier is the **ITI Verificador** at https://validar.iti.gov.br/ (ITI, the ICP-Brasil root authority).
**[NOT CONFIRMED]** I did not check that it accepts a detached .txt + .p7s pair. Third-party vendor support pages point to it for checking signed time-clock documents (e.g. PDF comprovantes, PAdES).

---

## 4. Open points / uncertainties (summary)

1. **Version:** the official AFD is `004` (not 003) and the AEJ is `002` since 31/07/2026. The only change is CNPJ fields becoming alphanumeric (A).
   The PDF has no changelog, so I don't know whether a transition period lets `003` still be accepted.
2. **SHA-256 chain:** the official text lists the inputs but not the exact byte string. Unspecified: separator, padded vs trimmed, hex case, first record, and whether the chain is per establishment or per file.
   Recommended: positions 001–073 as written + previous 64-hex hash; nothing for the first. This comes from third-party implementations only.
3. **CRC input:** I assume line positions 1..(n−4). The algorithm (KERMIT) and the check value are official.
4. **What the .p7s covers:** presumably the whole file, signature line included. Not stated.
5. **AFD padding of numeric fields** (counts, CNO/CAEPF when empty, the 14-position CPF in type 2): rule 7 says left + spaces. Zeros are common in practice. The parser should accept both.
6. **AEJ:** trailing-pipe behaviour (FAQ example), seqEntSaida for `D` records, nrRep padding, and the file name are not fully specified.
7. **"Portaria MTE 3.717/2023"** in the brief does not exist as such. The real one is **MTP 3.717/2022** (only the AEJ deadline, art. 97).
