typedef void sockaddr_in;
const char *explain_errno_bind(int errnum, int socket, const sockaddr_in *sock_addr, int sock_addr_size);
const char *explain_errno_write(int errnum, int fildes, const void *data, long data_size);
