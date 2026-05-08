# QOMO Device HEX Protocol Knowledge Base

## Overview

The protocol uses fixed-length 3-byte HEX frames for transmitting device button events over UART communication.

Example frames:

```text
2081a1
2091b1
27ae89
```

Typical serial communication settings:

```text
28800 baud
8 data bits
1 stop bit
no parity
```

---

# Frame Structure

Each message consists of 3 bytes:

```text
[B1][B2][B3]
```

---

# Byte Definitions

## Byte 1 (B1)

Contains the upper bits of the device ID.

Structure:

```text
0x20 | highNibble(deviceId)
```

Examples:

| Device ID | B1 |
|---|---|
| 1 | 0x20 |
| 16 | 0x21 |
| 100 | 0x26 |
| 211 | 0x2D |

---

## Byte 2 (B2)

Contains:

- upper 4 bits = button identifier
- lower 4 bits = lower bits of device ID

Structure:

```text
buttonPrefix | lowNibble(deviceId)
```

---

# Button Mapping

| Button | Prefix |
|---|---|
| A | 0x80 |
| B | 0x90 |
| C | 0xA0 |
| D | 0xB0 |
| E | 0xC0 |
| F | 0xD0 |
| Ruka | 0xE0 |

---

## Byte 3 (B3)

Checksum byte.

Generated using XOR:

```text
B3 = B1 XOR B2
```

The checksum is used to validate frame integrity.

---

# Device ID Decoding

The device ID is reconstructed from the lower nibble of B1 and the lower nibble of B2.

Formula:

```text
deviceId =
((B1 & 0x0F) << 4) |
(B2 & 0x0F)
```

---

# Button Decoding

The button is determined from the upper nibble of B2.

Formula:

```text
button = B2 & 0xF0
```

---

# Examples

## Device 1, Button A

Frame:

```text
20 81 A1
```

Explanation:

```text
B1 = 0x20
B2 = 0x81
B3 = 0x20 XOR 0x81 = 0xA1
```

---

## Device 1, Button B

Frame:

```text
20 91 B1
```

---

## Device 211, Button C

Frame:

```text
2D A3 8E
```

Verification:

```text
0x2D XOR 0xA3 = 0x8E
```

---

# Frame Validation

A received frame is valid when:

```text
(B1 XOR B2) == B3
```

---

# Protocol Characteristics

- Fixed-length 3-byte frames
- Stateless encoding
- Deterministic frame generation
- Simple XOR checksum
- No lookup database required
- Device IDs and buttons can be generated and decoded algorithmically

---

# Practical Benefits

The protocol allows:

- fast frame generation
- low memory usage
- easy validation
- simple decoding
- deterministic reconstruction of device events

No full dataset of device codes needs to be stored.
