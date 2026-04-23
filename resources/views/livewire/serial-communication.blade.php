<div>
    <div x-data="serialCommunication()" x-init="init()">
        <div class="flex items-center mt-4">
            <h1>Sériová komunikácia</h1>
            <div :class="isConnected ? 'bg-green-500' : 'bg-red-500'"
                class="w-4 h-4 ml-2 transition-colors duration-300 rounded-full"></div>
            <span class="ml-2" x-text="isConnected ? 'Pripojené' : 'Odpojené'"></span>
        </div>
        <x-primary-button @click="connect" x-bind:disabled="isConnected">Pripojiť</x-primary-button>
        <x-secondary-button @click="startCommunication" x-bind:disabled="!isConnected">Začať
            komunikáciu</x-secondary-button>
        <x-secondary-button @click="stopCommunication" x-bind:disabled="!isConnected">Zastaviť
            komunikáciu</x-secondary-button>
        <x-secondary-button @click="closeConnection" x-bind:disabled="!isConnected">Odpojiť</x-secondary-button>

        <div class="mt-4">
            <h3>Prijaté dáta:</h3>
            <pre x-text="receivedData" class="p-4 overflow-auto font-mono rounded-lg max-h-96"></pre>
        </div>
        @if ($result)
            <div class="mt-4">
                <div class="p-4 overflow-auto font-mono rounded-lg max-h-96">{{ $result }}</div>
            </div>
        @endif
    </div>
</div>

<script>
    function serialCommunication() {
        return {
            unsupportedApiNoticeKey: `serial-api-unsupported-session-{{ session()->getId() }}`,
            baudRate: 28800,
            dataBits: 8,
            parity: 'none',
            stopBits: 1,
            flowControl: 'none',
            receivedData: '',
            isConnected: false,
            serialPort: null,
            reader: null,
            writer: null,
            isReading: false,

            init() {
                if ('serial' in navigator) {
                    console.log('Web Serial API is supported');
                    this.closeConnection();
                } else {
                    console.error('Web Serial API not supported in this browser.');
                    this.showUnsupportedApiNoticeOncePerLogin();
                }
            },

            showUnsupportedApiNoticeOncePerLogin() {
                try {
                    if (sessionStorage.getItem(this.unsupportedApiNoticeKey)) {
                        return;
                    }

                    sessionStorage.setItem(this.unsupportedApiNoticeKey, '1');
                } catch (error) {
                    console.warn('Unable to persist Web Serial API notice state:', error);
                }

                alert('Web Serial API not supported in this browser.');
            },

            async connect() {
                try {
                    console.log('Attempting to connect...');
                    this.serialPort = await navigator.serial.requestPort();
                    await this.serialPort.open({
                        baudRate: this.baudRate,
                        dataBits: this.dataBits,
                        parity: this.parity,
                        stopBits: this.stopBits,
                        flowControl: this.flowControl
                    });
                    this.isConnected = true;
                    console.log('Connected successfully');

                    this.writer = this.serialPort.writable.getWriter();
                    console.log('Writer created');

                    await this.initializeDevice();
                } catch (error) {
                    console.error('Failed to connect:', error);
                }
            },

            async initializeDevice() {
                try {
                    console.log('Initializing device...');
                    await this.sendHexCommand("f400c00236", 5);
                    await this.sendHexCommand("f500000101f5", 6);
                    await this.sendHexCommand("f54b4e050200000601f0", 10);
                    console.log("Device initialized");
                } catch (error) {
                    console.error('Failed to initialize device:', error);
                }
            },

            async startCommunication() {
                try {
                    console.log('Starting communication...');
                    await this.sendHexCommand("5b80db", 3);
                    await this.sendHexCommand("5a80da", 3);
                    console.log("Communication started");

                    this.isReading = true;
                    this.readData();
                } catch (error) {
                    console.error('Failed to start communication:', error);
                }
            },

            async stopCommunication() {
                try {
                    console.log('Stopping communication...');
                    await this.sendHexCommand("5b80db", 3);
                    console.log("Communication stop command sent");
                    this.isReading = false;
                    if (this.reader) {
                        try {
                            await this.reader.cancel();
                            console.log("Reader cancelled");
                        } catch (error) {
                            console.log("Reader already cancelled or released");
                        }
                        this.reader = null;
                    }
                } catch (error) {
                    console.error('Failed to stop communication:', error);
                }
            },

            async readData() {
                console.log('Starting to read data...');
                let deviceCodes = [];
                let deviceCount = 0;
                let lastCode = null;

                try {
                    while (this.isReading) {
                        this.reader = this.serialPort.readable.getReader();
                        try {
                            while (this.isReading) {
                                const {
                                    value,
                                    done
                                } = await this.reader.read();
                                if (done) {
                                    console.log('Reading finished');
                                    break;
                                }
                                const hexData = this.arrayBufferToHex(value);
                                console.log("Received data (hex):", hexData);

                                // Ignoruj duplicitný kód
                                if (hexData === lastCode) {
                                    console.log("Ignoring duplicate code:", hexData);
                                    continue;
                                }
                                lastCode = hexData;

                                deviceCodes.push(hexData);

                                const checkResult = await this.$wire.checkCode(hexData);
                                // this.receivedData += checkResult + '\n';

                                if (deviceCodes.length === 1) {
                                    deviceCount++;
                                    this.receivedData += `Device ${deviceCount}: ${hexData}`;
                                } else if (deviceCodes.length < 7) {
                                    this.receivedData = this.receivedData.slice(0, -
                                        1); // Odstráni posledný znak ('\n')
                                    this.receivedData += `, ${hexData}`;
                                } else {
                                    this.receivedData = this.receivedData.slice(0, -
                                        1); // Odstráni posledný znak ('\n')
                                    this.receivedData += `, ${hexData}\n`;
                                    deviceCodes = [];
                                    lastCode = null; // Reset lastCode pre nové zariadenie
                                }
                            }
                        } finally {
                            this.reader.releaseLock();
                            this.reader = null;
                            console.log('Reader released');
                        }
                    }
                } catch (error) {
                    console.error('Error reading data:', error);
                }

                // Ak zostali nejaké kódy po ukončení čítania, pridáme nový riadok
                if (deviceCodes.length > 0 && deviceCodes.length < 7) {
                    this.receivedData += '\n';
                }

                console.log('Stopped reading data');
            },

            async sendHexCommand(hexString, length) {
                if (this.serialPort && this.isConnected && this.writer) {
                    const bytes = this.hexToByte(hexString);
                    console.log(`Sending command: ${hexString}, length: ${length}`);
                    console.log('Bytes being sent (decimal):', Array.from(bytes.slice(0, length)));
                    console.log('Bytes being sent (hex):', Array.from(bytes.slice(0, length)).map(b => b.toString(
                        16).padStart(2, '0')).join(' '));

                    await this.writer.write(bytes.slice(0, length));

                    console.log(`Command sent: ${hexString}`);
                } else {
                    console.error("Serial port is not open or connected");
                }
            },

            hexToByte(msg) {
                // Odstránime všetky medzery z reťazca
                msg = msg.replace(/\s/g, '');

                // Vytvoríme Uint8Array s dĺžkou reťazca delenou 2
                let comBuffer = new Uint8Array(msg.length / 2);

                // Prechádzame cez dĺžku poskytnutého reťazca
                for (let i = 0; i < msg.length; i += 2) {
                    // Konvertujeme každú dvojicu znakov na desiatkovú hodnotu
                    comBuffer[i / 2] = parseInt(msg.substr(i, 2), 16);
                }

                // Vrátime pole
                return comBuffer;
            },

            arrayBufferToHex(buffer) {
                return Array.from(new Uint8Array(buffer))
                    .map(b => b.toString(16).padStart(2, '0'))
                    .join('');
            },

            async closeConnection() {
                console.log('Closing connection...');
                if (this.isReading) {
                    this.stopCommunication();
                }
                this.isReading = false;
                if (this.reader) {
                    try {
                        await this.reader.cancel();
                        console.log('Reader cancelled');
                    } catch (error) {
                        console.log("Reader already cancelled or released");
                    }
                    this.reader = null;
                }
                if (this.writer) {
                    try {
                        await this.writer.close();
                        console.log('Writer closed');
                    } catch (error) {
                        console.log("Writer already closed");
                    }
                    this.writer = null;
                }
                if (this.serialPort) {
                    try {
                        await this.serialPort.close();
                        console.log('Serial port closed');
                    } catch (error) {
                        console.error("Error closing serial port:", error);
                    }
                    this.serialPort = null;
                }
                this.isConnected = false;
                console.log('Connection closed successfully');
            }
        }
    }
</script>
