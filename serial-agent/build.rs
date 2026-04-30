fn main() {
    if std::env::var("CARGO_CFG_TARGET_OS").as_deref() != Ok("windows") {
        return;
    }

    let mut resource = winresource::WindowsResource::new();
    resource.set("CompanyName", "KONSEZA");
    resource.set("ProductName", "Hlasovanie Serial Agent");
    resource.set("FileDescription", "Local serial gateway for Hlasovanie");
    resource.set("InternalName", "serial-agent");
    resource.set("OriginalFilename", "serial-agent.exe");
    resource.set("LegalCopyright", "Copyright (C) KONSEZA");

    if let Err(error) = resource.compile() {
        panic!("failed to compile Windows resources: {error}");
    }
}
